<?php

namespace App\Controller;

use App\Entity\ErrorLog;
use App\Service\FtpMediaStorage;
use App\Service\GraphicCodeImporter;
use App\Service\ModuleDefinitionLoader;
use App\Service\LotteryRegistrationValidator;
use App\Service\LotteryDailyEntryLimitService;
use App\Service\LotteryDailyLimitException;
use App\Service\LotteryDuplicateReceiptException;
use App\Service\LotteryReceiptValidator;
use App\Service\LotteryProductAllocation;
use App\Service\LotteryScratchcardAccess;
use App\Service\SystemSettingsProvider;
use App\Service\ScratchcardWinnerSelection\EveryScratchcardWinsSelector;
use App\Service\ScratchcardWinnerSelection\EveryScratchcardWinsWithPrioritySelector;
use App\Service\ScratchcardWinnerSelection\NearestPastHourScratchcardWinnerSelector;
use App\Service\WinnerPrizeCodeAssignmentService;
use App\Service\WinnerFormReminderService;
use App\Service\WinnerStatusEmailService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class FrontendController extends BaseController
{
    private const LOTTERY_FORM_TOKENS_SESSION_KEY = 'lottery_form_tokens';
    private const EMAIL_DOMAIN_WARNING_ACK_SESSION_KEY = 'email_domain_warning_ack_emails';
    private const EMAIL_DOMAIN_WARNING_ACK_TTL_SECONDS = 900;
    private const AGE_VERIFIED_SESSION_KEY = 'frontend_age_verified';


    private const COMMON_EMAIL_DOMAINS = [
        'gmail.com',
        'wp.pl',
        'onet.pl',
        'o2.pl',
        'interia.pl',
        'outlook.com',
        'hotmail.com',
        'yahoo.com',
        'icloud.com',
        'me.com',
        'proton.me',
        'protonmail.com',
        'gazeta.pl',
        'op.pl',
        'tlen.pl',
        'poczta.onet.pl',
    ];

    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        if ($template === 'frontend/zywiec.html.twig' && !array_key_exists('storeChains', $data)) {
            $data['storeChains'] = $this->fetchModuleEntries('sieci_sklepow', ['active' => 1], ['orderBy' => 'name']);
        }
        if ($template === 'frontend/zywiec.html.twig' && !array_key_exists('productAllocationSettings', $data)) {
            $lotterySettings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
            $data['productAllocationSettings'] = [
                'enabled' => LotteryProductAllocation::isProductBased($lotterySettings),
                'promoted' => LotteryProductAllocation::setting($lotterySettings, 'promoted_product_entry_count', 5),
                'other' => LotteryProductAllocation::setting($lotterySettings, 'other_product_entry_count', 1),
                'daily_limit' => LotteryProductAllocation::setting($lotterySettings, 'daily_product_limit', 4),
            ];
        }

        if ($template === 'frontend/zywiec.html.twig' && !array_key_exists('faqEntries', $data)) {
            $settings = is_array($data['settings'] ?? null) ? $data['settings'] : ($this->fetchModuleEntry('settings') ?? []);
            $data['faqEntries'] = $this->resolveConsumerFaqEntries($settings);
        }

        return parent::render($template, $data, $status);
    }

    private function isAgeVerified(Request $request): bool
    {
        if (!$request->hasSession()) {
            return false;
        }

        return $request->getSession()->get(self::AGE_VERIFIED_SESSION_KEY) === true;
    }

    private function markAgeVerified(Request $request): void
    {
        if ($request->hasSession()) {
            $request->getSession()->set(self::AGE_VERIFIED_SESSION_KEY, true);
        }
    }

    private function requireAgeVerification(Request $request): ?Response
    {
        if ($this->isAgeVerified($request)) {
            return null;
        }

        if ($request->isXmlHttpRequest() || $request->getMethod() !== 'GET') {
            return new JsonResponse([
                'success' => false,
                'message' => 'Przed skorzystaniem ze strony potwierdź pełnoletność.',
                'redirect' => '/',
            ], Response::HTTP_FORBIDDEN);
        }

        $target = $this->normalizeAgeGateTarget($request->getRequestUri());

        return new RedirectResponse('/?target=' . rawurlencode($target));
    }

    private function isAdultBirthDate(int $day, int $month, int $year): bool
    {
        if ($year < 1900 || !checkdate($month, $day, $year)) {
            return false;
        }

        $birthDate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        $adultThreshold = (new \DateTimeImmutable('today'))->modify('-18 years');

        return $birthDate <= $adultThreshold;
    }

    private function normalizeAgeGateTarget(string $target): string
    {
        $target = trim($target);
        if ($target === '' || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return '/loteria';
        }

        return $target;
    }

    public function metaJson(Request $request): Response
    {
        $metaPath = dirname(__DIR__, 2) . '/public_html/meta.json';
        if (!is_file($metaPath)) {
            return new JsonResponse([
                'error' => 'meta.json not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $content = (string) file_get_contents($metaPath);

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    public function home(Request $request): Response
    {
        $target = $this->normalizeAgeGateTarget((string) $request->query->get('target', '/loteria'));
        if ($this->isAgeVerified($request)) {
            return new RedirectResponse($target);
        }

        return $this->render('frontend/age_gate.html.twig', [
            'ageGateTarget' => $target,
        ]);
    }

    public function confirmAge(Request $request): Response
    {
        $day = (int) $request->request->get('day', 0);
        $month = (int) $request->request->get('month', 0);
        $year = (int) $request->request->get('year', 0);
        $target = $this->normalizeAgeGateTarget((string) $request->request->get('target', '/loteria'));

        if (!$this->isAdultBirthDate($day, $month, $year)) {
            $message = ($year < 1900 || !checkdate($month, $day, $year))
                ? 'Wpisz poprawną datę urodzenia.'
                : 'Ta strona jest przeznaczona wyłącznie dla osób pełnoletnich.';

            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'success' => false,
                    'message' => $message,
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->render('frontend/age_gate.html.twig', [
                'ageGateError' => $message,
                'ageGateTarget' => $target,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->markAgeVerified($request);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true, 'redirect' => $target]);
        }

        return new RedirectResponse($target);
    }

    public function loteriaM1(Request $request, array $parameters = []): Response
    {
        if (($ageGateResponse = $this->requireAgeVerification($request)) !== null) {
            return $ageGateResponse;
        }

        return $this->render($this->resolveRegistrationPageTemplate(), $this->buildLoteriaM1Context($request, $parameters));
    }

    public function winnersList(Request $request): Response
    {
        if (($ageGateResponse = $this->requireAgeVerification($request)) !== null) {
            return $ageGateResponse;
        }

        $offset = max(0, (int) $request->query->get('offset', 0));
        $requestedLimit = (int) $request->query->get('limit', 0);
        $limit = $requestedLimit > 0 ? min($requestedLimit, 50) : null;
        $winningCode = trim((string) $request->query->get('code', ''));
        if ($winningCode !== '') {
            $winningCode = mb_substr($winningCode, 0, 128);
            $winners = $this->resolveFrontendWinners(null, 0, $winningCode);

            return new JsonResponse([
                'winners' => $winners,
                'hasMore' => false,
                'nextOffset' => 0,
            ]);
        }

        $winners = $limit === null
            ? $this->resolveFrontendWinners(null, $offset)
            : $this->resolveFrontendWinners($limit + 1, $offset);
        $hasMore = $limit !== null && count($winners) > $limit;

        if ($limit !== null) {
            $winners = array_slice($winners, 0, $limit);
        }

        return new JsonResponse([
            'winners' => $winners,
            'hasMore' => $hasMore,
            'nextOffset' => $offset + count($winners),
        ]);
    }

    private function resolveFrontendWinnersPreview(int $limit = 5): array
    {
        if ($limit <= 0) {
            return [
                'winners' => [],
                'hasMore' => false,
            ];
        }

        $winners = $this->resolveFrontendWinners($limit + 1);

        return [
            'winners' => array_slice($winners, 0, $limit),
            'hasMore' => count($winners) > $limit,
        ];
    }

    private function resolveFrontendSellerWinners(string $centerId = '', ?int $limit = null): array
    {
        return [];
    }

    private function resolveFrontendWinners(?int $scratchWinnersLimit = null, int $offset = 0, ?string $winningCode = null): array
    {
        $limit = $scratchWinnersLimit !== null && $scratchWinnersLimit > 0 ? $scratchWinnersLimit : null;
        $winnerEntries = $this->fetchFrontendWinnerEntries($limit, $offset, $winningCode);
        $winners = [];

        foreach ($winnerEntries as $winnerEntry) {
            $prizeLabel = trim((string) ($winnerEntry['prize_display_name'] ?? ''));
            if ($prizeLabel === '') {
                $prizeLabel = trim((string) ($winnerEntry['prize_name'] ?? ''));
            }
            if ($prizeLabel === '') {
                $prizeLabel = 'Nagroda natychmiastowa';
            }

            $winners[] = [
                'prizeLabel' => $prizeLabel,
                'prizeImage' => trim((string) ($winnerEntry['prize_image'] ?? '')),
                'winningCode' => trim((string) ($winnerEntry['winning_code'] ?? '')),
                'isMainPrize' => false,
            ];
        }

        return $winners;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFrontendWinnerEntries(?int $limit = null, int $offset = 0, ?string $winningCode = null): array
    {
        $connection = $this->entityManager->getConnection();
        $winnersDefinition = $this->resolveModuleDefinition('zwyciezcy');
        $prizeTypesDefinition = $this->resolveModuleDefinition('rodzaje_nagrod');
        $statusesDefinition = $this->resolveModuleDefinition('ustawienia_statusow');
        $submissionsDefinition = $this->resolveModuleDefinition('loteria_zgloszenia');
        $codesDefinition = $this->resolveModuleDefinition('kody_zywiec');
        $winnersTable = $winnersDefinition['database']['table'] ?? null;
        $prizeTypesTable = $prizeTypesDefinition['database']['table'] ?? null;
        $statusesTable = $statusesDefinition['database']['table'] ?? null;
        $submissionsTable = $submissionsDefinition['database']['table'] ?? null;
        $codesTable = $codesDefinition['database']['table'] ?? null;

        if (
            !is_string($winnersTable) || $winnersTable === ''
            || !is_string($prizeTypesTable) || $prizeTypesTable === ''
            || !is_string($statusesTable) || $statusesTable === ''
            || !is_string($submissionsTable) || $submissionsTable === ''
            || !is_string($codesTable) || $codesTable === ''
        ) {
            return [];
        }

        try {
            $queryBuilder = $connection->createQueryBuilder()
                ->select(
                    'w.id',
                    'p.name AS prize_name',
                    'p.nazwa_nagrody_do_wyswietlenia AS prize_display_name',
                    'p.zdjecie_nagrody AS prize_image',
                    'c.kod AS winning_code'
                )
                ->from((string) $winnersTable, 'w')
                ->innerJoin('w', (string) $statusesTable, 's', 's.id = w.stan')
                ->innerJoin('w', (string) $prizeTypesTable, 'p', 'p.id = w.rodzaj_wygranej')
                ->leftJoin('w', (string) $submissionsTable, 'e', 'e.id = w.zgloszenie')
                ->leftJoin('e', (string) $codesTable, 'c', 'c.id = e.seller_code')
                ->where('s.wyswietlaj_w_sekcji_laureatow = :displayStatusInWinners')
                ->andWhere('p.wyswietlaj_w_sekcji_laureatow = :displayInWinners')
                ->setParameter('displayStatusInWinners', 1)
                ->setParameter('displayInWinners', 1)
                ->orderBy('w.id', 'DESC');

            $normalizedWinningCode = trim((string) $winningCode);
            if ($normalizedWinningCode !== '') {
                $queryBuilder
                    ->andWhere('UPPER(TRIM(c.kod)) = :winningCodeSearch')
                    ->setParameter('winningCodeSearch', mb_strtoupper($normalizedWinningCode));
            }

            if ($limit !== null && $limit > 0) {
                $queryBuilder->setMaxResults($limit);
            }
            if ($offset > 0) {
                $queryBuilder->setFirstResult($offset);
            }

            return $queryBuilder->executeQuery()->fetchAllAssociative();
        } catch (\Throwable $exception) {
            return [];
        }
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $normalized = mb_strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function buildLoteriaM1Context(Request $request, array $parameters = [], ?string $forcedCenterId = null): array
    {
        $settings = $this->fetchModuleEntry('settings') ?? [];
        $allCenters = $this->fetchActiveShoppingCenters();
        $selectedCenterId = $forcedCenterId ?? $this->resolveSelectedCenterIdFromRequest($request, $allCenters, $parameters);
        $selectedCenter = $this->resolveCenterById($allCenters, $selectedCenterId);
        if ($selectedCenter === null) {
            $selectedCenterId = '';
        }

        if ($selectedCenterId === '' && $allCenters !== []) {
            $selectedCenterId = trim((string) ($allCenters[0]['id'] ?? ''));
            $selectedCenter = $this->resolveCenterById($allCenters, $selectedCenterId);
        }

        $lotterySettings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
        $currentLottery = $this->resolveCurrentLottery($lotterySettings);
        $upcomingLottery = $this->resolveUpcomingLottery($lotterySettings);
        $endedLottery = $this->resolveEndedLottery($lotterySettings);
        $currentLotteryType = trim((string) ($currentLottery['type'] ?? ''));
        $currentNewsletterLottery = $this->resolveCurrentLottery($lotterySettings);
        $lotteryCenters = $selectedCenter !== null ? [$selectedCenter] : [];
        $storeOptionsByCenter = $this->resolveStoreOptionsByCenter($lotteryCenters);
        $faqEntries = $this->resolveConsumerFaqEntries($settings);
        $textsOnSiteEntries = $this->fetchModuleEntries('teksty_na_stronie');
        $lotteryRulesContent = $this->resolveRulesContent($textsOnSiteEntries);
        $sellerPageEntries = $this->fetchModuleEntries('strona_sprzedawcy');
        $sellerPageContent = $this->resolveSellerPageContent($sellerPageEntries);
        $winnersPreview = $this->resolveFrontendWinnersPreview(5);
        $latestWinners = $winnersPreview['winners'];
        $hasMoreWinners = $winnersPreview['hasMore'];
        $scheduleDraws = $this->resolveFrontendScheduleDraws();
        $lotterySubmission = $_SESSION['lottery_submission'] ?? null;
        $newsletterSubmission = $_SESSION['newsletter_submission'] ?? null;
        $newsletterConfirmation = $_SESSION['newsletter_confirmation'] ?? null;
        unset($_SESSION['lottery_submission']);
        unset($_SESSION['newsletter_submission']);
        unset($_SESSION['newsletter_confirmation']);

        return [
            'settings' => $settings,
            'centers' => $allCenters,
            'centerUrls' => [],
            'selectedCenterId' => $selectedCenterId,
            'selectedCenter' => $selectedCenter,
            'sellerCenterUrl' => $this->buildSellerRegistrationUrl($selectedCenterId, $allCenters),
            'lotteries' => [],
            'currentLottery' => $currentLottery,
            'upcomingLottery' => $upcomingLottery,
            'endedLottery' => $endedLottery,
            'currentNewsletterLottery' => $currentNewsletterLottery,
            'lotteryCenters' => $lotteryCenters,
            'storeOptionsByCenter' => $storeOptionsByCenter,
            'lotterySubmission' => $lotterySubmission,
            'lotteryFormToken' => $this->issueLotteryFormToken(),
            'newsletterSubmission' => $newsletterSubmission,
            'newsletterConfirmation' => $newsletterConfirmation,
            'faqEntries' => $faqEntries,
            'lotteryRulesContent' => $lotteryRulesContent,
            'sellerPageContent' => $sellerPageContent,
            'latestWinners' => $latestWinners,
            'hasMoreWinners' => $hasMoreWinners,
            'scheduleDraws' => $scheduleDraws,
            'submissionTemplate' => $this->resolveSubmissionTemplateForLotteryType($currentLotteryType),
        ];
    }

    private function resolveConfiguredWinnersLotteryId(): string
    {
        $lotterySettings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];

        return trim((string) ($lotterySettings['wyniki_loteria'] ?? ''));
    }

    private function maskWinnerEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return '';
        }

        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return $this->maskWinnerEmailPart($email);
        }

        [$local, $domain] = $parts;

        return $this->maskWinnerEmailPart($local) . '@' . $this->maskWinnerDomain($domain);
    }

    private function maskWinnerEmailPart(string $value): string
    {
        $length = mb_strlen($value);
        if ($length <= 1) {
            return str_repeat('*', 3);
        }

        if ($length === 2) {
            return mb_substr($value, 0, 1) . str_repeat('*', 3);
        }

        return mb_substr($value, 0, 1)
            . str_repeat('*', 3)
            . mb_substr($value, -1, 1);
    }

    private function maskWinnerDomain(string $domain): string
    {
        $segments = array_values(array_filter(explode('.', $domain), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return $this->maskWinnerEmailPart($domain);
        }

        $maskedSegments = [];
        $lastIndex = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            if ($index === $lastIndex) {
                $maskedSegments[] = $segment;
                continue;
            }

            $maskedSegments[] = $this->maskWinnerEmailPart($segment);
        }

        return implode('.', $maskedSegments);
    }

    public function m1LpSprzedawcow(Request $request, array $parameters = []): Response
    {
        $settings = $this->fetchModuleEntry('settings') ?? [];
        if (!$this->isSellerPageEnabled($settings)) {
            throw $this->createNotFoundException('Nie znaleziono strony.');
        }
        $sellerCenters = $this->fetchActiveShoppingCenters();
        $selectedSellerCenterId = $this->resolveSellerCenterContextId($request, $sellerCenters, $parameters);
        $selectedSellerCenter = $this->resolveCenterById($sellerCenters, $selectedSellerCenterId);
        if ($selectedSellerCenter === null) {
            $selectedSellerCenterId = '';
        }
        $scratchLotteries = [];
        $filteredSellerLotteries = $this->filterLotteriesByCenter($scratchLotteries, $selectedSellerCenterId);
        $currentSellerLottery = $this->resolveCurrentLottery($this->fetchModuleEntry('loteria_ustawienia') ?? []);
        $upcomingSellerLottery = $this->resolveUpcomingLottery($this->fetchModuleEntry('loteria_ustawienia') ?? []);
        $sellerSubmission = $_SESSION['seller_submission'] ?? null;
        $sellerConfirmation = $_SESSION['seller_confirmation'] ?? null;
        $sellerTicketsCheck = $_SESSION['seller_tickets_check'] ?? null;
        $sellerTicketsCheckErrors = $_SESSION['seller_tickets_check_errors'] ?? [];
        $sellerTicketsCheckFormData = $_SESSION['seller_tickets_check_form_data'] ?? [];
        $sellerFaqEntries = array_values(array_filter(
            $this->fetchModuleEntries(
                'faq_sprzedawcy',
                [],
                ['orderBy' => 'position', 'direction' => 'asc']
            ),
            static function (array $entry): bool {
                if (!array_key_exists('active', $entry)) {
                    return true;
                }

                $active = $entry['active'];
                if ($active === null || $active === '') {
                    return true;
                }

                return in_array(mb_strtolower(trim((string) $active)), ['1', 'true', 'yes', 'on'], true);
            }
        ));
        $textsOnSiteEntries = $this->fetchModuleEntries('teksty_na_stronie');
        $lotteryRulesContent = $this->resolveRulesContent($textsOnSiteEntries);
        $sellerPageEntries = $this->fetchModuleEntries('strona_sprzedawcy');
        $sellerPageContent = $this->resolveSellerPageContent($sellerPageEntries);
        $latestWinners = $this->resolveFrontendSellerWinners($selectedSellerCenterId, 5);
        unset($_SESSION['seller_submission']);
        unset($_SESSION['seller_confirmation']);
        unset($_SESSION['seller_tickets_check']);
        unset($_SESSION['seller_tickets_check_errors']);
        unset($_SESSION['seller_tickets_check_form_data']);

        return $this->render($this->resolveRegistrationPageTemplate($settings), [
            'settings' => $settings,
            'sellerSubmission' => $sellerSubmission,
            'sellerConfirmation' => $sellerConfirmation,
            'sellerErrors' => [],
            'sellerFormData' => [],
            'sellerCenters' => $sellerCenters,
            'sellerStoreOptionsByCenter' => $this->resolveSellerStoreOptionsByCenter(),
            'selectedSellerCenterId' => $selectedSellerCenterId,
            'selectedSellerCenter' => $selectedSellerCenter,
            'sellerTicketsCheck' => $sellerTicketsCheck,
            'sellerTicketsCheckErrors' => $sellerTicketsCheckErrors,
            'sellerTicketsCheckFormData' => $sellerTicketsCheckFormData,
            'sellerFaqEntries' => $sellerFaqEntries,
            'lotteryRulesContent' => $lotteryRulesContent,
            'sellerPageContent' => $sellerPageContent,
            'latestWinners' => $latestWinners,
            'currentSellerLottery' => $currentSellerLottery,
            'upcomingSellerLottery' => $upcomingSellerLottery,
        ]);
    }

    public function downloadPrettyFile(Request $request, array $parameters = []): Response
    {
        $slug = trim((string) ($parameters['slug'] ?? ''));
        if ($slug === '') {
            throw $this->createNotFoundException('Nie znaleziono pliku.');
        }

        $filesBySlug = $this->buildPrettyFilesMap();
        $relativePath = $filesBySlug[$slug] ?? '';
        if ($relativePath === '') {
            throw $this->createNotFoundException('Nie znaleziono pliku.');
        }

        $absolutePath = $this->resolvePublicFileAbsolutePath($relativePath);
        if ($absolutePath === null) {
            throw $this->createNotFoundException('Nie znaleziono pliku.');
        }

        return $this->createInlineFileResponse($absolutePath);
    }

    public function serveWinnerReceipt(Request $request, array $parameters = []): Response
    {
        $filename = trim((string) ($parameters['filename'] ?? ''));
        $absolutePath = $this->resolveWinnerReceiptAbsolutePath($filename);
        if ($absolutePath === null) {
            throw $this->createNotFoundException('Nie znaleziono pliku.');
        }

        if (!$this->isPublicFileSource('winner_receipts')) {
            if (!$this->authService->requireAuth()) {
                return new Response('Wymagane logowanie.', Response::HTTP_UNAUTHORIZED);
            }

            if ($response = $this->denyUnlessPermission('zwyciezcy', 'edit')) {
                return $response;
            }
        }

        return $this->createInlineFileResponse($absolutePath);
    }

    public function submitSellerRegistration(Request $request): Response
    {
        if (!$this->isSellerPageEnabled()) {
            throw $this->createNotFoundException('Nie znaleziono strony.');
        }

        $data = $request->request->all();
        $fullName = trim((string) ($data['full_name'] ?? ''));
        if ($fullName !== '') {
            $nameParts = preg_split('/\s+/', $fullName) ?: [];
            $data['imie'] = $nameParts !== [] ? array_shift($nameParts) : '';
            $data['nazwisko'] = trim(implode(' ', $nameParts));
        }

        $errors = [];
        $errorsByField = [];
        $isAjax = $request->isXmlHttpRequest();
        $addError = static function (string $field, string $message) use (&$errors, &$errorsByField): void {
            $errors[] = $message;
            $errorsByField[] = [
                'field' => $field,
                'message' => $message,
            ];
        };

        $requiredFields = [
            'imie' => 'Imię jest wymagane.',
            'nazwisko' => 'Nazwisko jest wymagane.',
            'email' => 'Adres e-mail jest wymagany.',
            'telefon' => 'Numer telefonu jest wymagany.',
            'centrum_handlowe' => 'Wybór centrum handlowego jest wymagany.',
        ];

        foreach ($requiredFields as $field => $message) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $addError($field, $message);
            }
        }

        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $addError('email', 'Adres e-mail ma nieprawidłowy format.');
        } elseif ($email !== '' && ($suggestedDomain = $this->resolveSuggestedEmailDomain($email)) !== null && !$this->isSuggestedEmailDomainConfirmed($data, $email)) {
            $this->rememberSuggestedEmailDomainWarning($email);
            $data['email_domain_warning_ack'] = $email;
            $addError('email', sprintf('Sprawdź domenę adresu e-mail. Czy chodziło o %s? Jeśli adres jest poprawny, wyślij formularz ponownie.', $suggestedDomain));
        } elseif ($email !== '' && $this->isEmailAssignedToConfirmedSellerAccount($email)) {
            $addError('email', 'Podany adres e-mail jest zarejestrowany w loterii w części dla sprzedawców.');
        }

        $phone = trim((string) ($data['telefon'] ?? ''));
        if ($phone !== '' && !preg_match('/^\d{9}$/', $phone)) {
            $addError('telefon', 'Numer telefonu musi składać się z dokładnie 9 cyfr.');
        }

        if (($data['rules_accepted'] ?? '') !== 'yes') {
            $addError('rules_accepted', 'Akceptacja regulaminu jest wymagana.');
        }

        $sellerCenters = $this->fetchActiveShoppingCenters();
        $sellerCenterContextId = $this->resolveSellerCenterContextId($request, $sellerCenters, [], $data);
        $selectedSellerCenter = $this->resolveCenterById($sellerCenters, $sellerCenterContextId);
        $sellerPageContent = $this->resolveSellerPageContent($this->fetchModuleEntries('strona_sprzedawcy'));
        $scratchLotteries = [];
        $filteredSellerLotteries = $this->filterLotteriesByCenter($scratchLotteries, $sellerCenterContextId);
        $currentSellerLottery = $this->resolveCurrentLottery($this->fetchModuleEntry('loteria_ustawienia') ?? []);
        $upcomingSellerLottery = $this->resolveUpcomingLottery($this->fetchModuleEntry('loteria_ustawienia') ?? []);

        $centerId = trim((string) ($data['centrum_handlowe'] ?? ''));
        if ($sellerCenterContextId !== '') {
            $centerId = $sellerCenterContextId;
            $data['centrum_handlowe'] = $sellerCenterContextId;
        }

        $storeOptionsByCenter = $this->resolveSellerStoreOptionsByCenter();

        if ($errors === []) {
            $recaptchaError = $this->validateRecaptcha(
                $data['g-recaptcha-response'] ?? null,
                $request->getClientIp()
            );
            if ($recaptchaError !== null) {
                $addError('recaptcha', $recaptchaError);
            }
        }

        $definition = $this->resolveModuleDefinition('sprzedawcy');
        $table = $definition['database']['table'] ?? null;
        if (!is_string($table) || $table === '') {
            $addError('form', 'Brak konfiguracji modułu sprzedawców.');
        }

        if ($errors !== []) {
            if ($isAjax) {
                return new JsonResponse([
                    'success' => false,
                    'errorsByField' => $errorsByField,
                ], 422);
            }

            $settings = $this->fetchModuleEntry('settings') ?? [];

            return $this->render($this->resolveRegistrationPageTemplate($settings), [
                'settings' => $settings,
                'sellerSubmission' => null,
                'sellerConfirmation' => null,
                'sellerErrors' => $errors,
                'sellerFormData' => $data,
                'sellerCenters' => $sellerCenters,
                'sellerStoreOptionsByCenter' => $storeOptionsByCenter,
                'selectedSellerCenterId' => $sellerCenterContextId,
                'selectedSellerCenter' => $selectedSellerCenter,
                'sellerTicketsCheck' => null,
                'sellerTicketsCheckErrors' => [],
                'sellerTicketsCheckFormData' => [],
                'sellerPageContent' => $sellerPageContent,
                'currentSellerLottery' => $currentSellerLottery,
                'upcomingSellerLottery' => $upcomingSellerLottery,
            ]);
        }

        $currentScratchLottery = $currentSellerLottery;
        $currentScratchLotteryId = trim((string) ($currentScratchLottery['id'] ?? ''));
        if ($currentScratchLotteryId === '') {
            $addError('form', 'Brak aktywnej loterii zdrapkowej.');
        }

        if ($errors !== []) {
            if ($isAjax) {
                return new JsonResponse([
                    'success' => false,
                    'errorsByField' => $errorsByField,
                ], 422);
            }

            $settings = $this->fetchModuleEntry('settings') ?? [];

            return $this->render($this->resolveRegistrationPageTemplate($settings), [
                'settings' => $settings,
                'sellerSubmission' => null,
                'sellerConfirmation' => null,
                'sellerErrors' => $errors,
                'sellerFormData' => $data,
                'sellerCenters' => $sellerCenters,
                'sellerStoreOptionsByCenter' => $storeOptionsByCenter,
                'selectedSellerCenterId' => $sellerCenterContextId,
                'selectedSellerCenter' => $selectedSellerCenter,
                'sellerTicketsCheck' => null,
                'sellerTicketsCheckErrors' => [],
                'sellerTicketsCheckFormData' => [],
                'sellerPageContent' => $sellerPageContent,
                'currentSellerLottery' => $currentSellerLottery,
                'upcomingSellerLottery' => $upcomingSellerLottery,
            ]);
        }

        $connection = $this->entityManager->getConnection();
        $sellerCode = $this->generateUniqueSellerCode($connection, $table);
        $existing = $connection->createQueryBuilder()
            ->select('id', 'email', 'confirmed')
            ->from($table)
            ->where('email = :email')
            ->setParameter('email', $email)
            ->executeQuery()
            ->fetchAssociative();

        $submittedAt = new \DateTimeImmutable();
        $token = bin2hex(random_bytes(16));
        $confirmationLink = rtrim($request->getSchemeAndHttpHost(), '/')
            . '/sprzedawcy/potwierdz/' . urlencode($token);

        if ($existing) {
            if (!empty($existing['confirmed'])) {
                $addError('email', 'Ten adres e-mail jest już potwierdzony jako sprzedawca.');
            } else {
                $connection->update($table, [
                    'imie' => trim((string) ($data['imie'] ?? '')),
                    'nazwisko' => trim((string) ($data['nazwisko'] ?? '')),
                    'email' => $email,
                    'telefon' => trim((string) ($data['telefon'] ?? '')),
                    'centrum_handlowe' => $centerId,
                    'kod_sprzedawcy' => $sellerCode,
                    'loteria' => $currentScratchLotteryId,
                    'confirmation_token' => $token,
                    'confirmation_sent_at' => $submittedAt->format('Y-m-d H:i:s'),
                ], ['id' => $existing['id']]);

                $this->sendSellerRegistrationConfirmationEmail(
                    $email,
                    $confirmationLink,
                    rtrim($request->getSchemeAndHttpHost(), '/') . $this->buildSellerRegistrationUrl($centerId, $sellerCenters)
                );
                $_SESSION['seller_submission'] = ['email' => $email];

                $redirectUrl = $this->resolveSellerPostSubmitRedirectUrl($request, $sellerCenterContextId, $sellerCenters);
                if ($isAjax) {
                    return new JsonResponse([
                        'success' => true,
                        'redirectUrl' => $redirectUrl,
                        'email' => $email,
                    ]);
                }

                return new RedirectResponse($redirectUrl);
            }
        }

        if ($errors !== []) {
            if ($isAjax) {
                return new JsonResponse([
                    'success' => false,
                    'errorsByField' => $errorsByField,
                ], 422);
            }

            $settings = $this->fetchModuleEntry('settings') ?? [];

            return $this->render($this->resolveRegistrationPageTemplate($settings), [
                'settings' => $settings,
                'sellerSubmission' => null,
                'sellerConfirmation' => null,
                'sellerErrors' => $errors,
                'sellerFormData' => $data,
                'sellerCenters' => $sellerCenters,
                'sellerStoreOptionsByCenter' => $storeOptionsByCenter,
                'selectedSellerCenterId' => $sellerCenterContextId,
                'selectedSellerCenter' => $selectedSellerCenter,
                'sellerTicketsCheck' => null,
                'sellerTicketsCheckErrors' => [],
                'sellerTicketsCheckFormData' => [],
                'sellerPageContent' => $sellerPageContent,
                'currentSellerLottery' => $currentSellerLottery,
                'upcomingSellerLottery' => $upcomingSellerLottery,
            ]);
        }

        $connection->insert($table, [
            'imie' => trim((string) ($data['imie'] ?? '')),
            'nazwisko' => trim((string) ($data['nazwisko'] ?? '')),
            'email' => $email,
            'telefon' => trim((string) ($data['telefon'] ?? '')),
            'centrum_handlowe' => $centerId,
            'kod_sprzedawcy' => $sellerCode,
            'loteria' => $currentScratchLotteryId,
            'confirmed' => false,
            'confirmation_token' => $token,
            'confirmation_sent_at' => $submittedAt->format('Y-m-d H:i:s'),
            'confirmed_at' => null,
        ]);

        $this->sendSellerRegistrationConfirmationEmail(
            $email,
            $confirmationLink,
            rtrim($request->getSchemeAndHttpHost(), '/') . $this->buildSellerRegistrationUrl($centerId, $sellerCenters)
        );
        $_SESSION['seller_submission'] = ['email' => $email];

        $redirectUrl = $this->resolveSellerPostSubmitRedirectUrl($request, $sellerCenterContextId, $sellerCenters);
        if ($isAjax) {
            return new JsonResponse([
                'success' => true,
                'redirectUrl' => $redirectUrl,
                'email' => $email,
            ]);
        }

        return new RedirectResponse($redirectUrl);
    }


    private function resolveSellerPostSubmitRedirectUrl(Request $request, string $sellerCenterContextId, array $sellerCenters): string
    {
        $redirectTo = trim((string) $request->request->get('redirect_to', ''));
        if ($redirectTo !== '' && str_starts_with($redirectTo, '/')) {
            return $this->appendFragmentToUrl($redirectTo, 'zglos');
        }

        return $this->appendFragmentToUrl(
            $this->buildSellerRegistrationUrl($sellerCenterContextId, $sellerCenters),
            'zglos'
        );
    }

    public function confirmSellerRegistration(Request $request, array $parameters): Response
    {
        if (!$this->isSellerPageEnabled()) {
            throw $this->createNotFoundException('Nie znaleziono strony.');
        }

        $token = trim((string) ($parameters['token'] ?? ''));
        $definition = $this->resolveModuleDefinition('sprzedawcy');
        $table = $definition['database']['table'] ?? null;
        $status = 'invalid';
        $centerId = '';

        if ($token !== '' && is_string($table) && $table !== '') {
            $connection = $this->entityManager->getConnection();
            $entry = $connection->createQueryBuilder()
                ->select('*')
                ->from($table)
                ->where('confirmation_token = :token')
                ->setParameter('token', $token)
                ->executeQuery()
                ->fetchAssociative();

            if ($entry) {
                $centerId = trim((string) ($entry['centrum_handlowe'] ?? ''));

                if (!empty($entry['confirmed'])) {
                    $status = 'already_confirmed';
                } else {
                    $status = 'confirmed';
                    $confirmedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                    $connection->update($table, [
                        'confirmed' => true,
                        'confirmed_at' => $confirmedAt,
                    ], [
                        'id' => $entry['id'],
                    ]);

                    $this->sendSellerAccountConfirmedEmail(
                        (string) ($entry['email'] ?? ''),
                        (string) ($entry['kod_sprzedawcy'] ?? ''),
                        rtrim($request->getSchemeAndHttpHost(), '/') . $this->buildSellerRegistrationUrl($centerId, $this->fetchActiveShoppingCenters())
                    );
                }
            }
        }

        $_SESSION['seller_confirmation'] = ['status' => $status];

        if ($centerId === '') {
            $centerId = trim((string) $request->query->get('centrum', ''));
        }

        return new RedirectResponse($this->appendFragmentToUrl(
            $this->buildSellerRegistrationUrl($centerId, $this->fetchActiveShoppingCenters()),
            'zglos'
        ));
    }


    public function checkSellerTickets(Request $request): Response
    {
        if (!$this->isSellerPageEnabled()) {
            throw $this->createNotFoundException('Nie znaleziono strony.');
        }

        $data = $request->request->all();
        $sellerCenters = $this->fetchActiveShoppingCenters();
        $centerContextId = $this->resolveSellerCenterContextId($request, $sellerCenters, [], $data);

        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $sellerCode = mb_strtoupper(trim((string) ($data['kod_sprzedawcy'] ?? '')));
        $errors = [];

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $sellerCode === '') {
            $errors[] = 'Wypełniej poprawnie zaznaczone pola';
        }

        $lotteries = [];
        $currentLottery = $this->resolveCurrentLottery($this->fetchModuleEntry('loteria_ustawienia') ?? []);
        $currentLotteryId = trim((string) ($currentLottery['id'] ?? ''));
        if ($currentLotteryId === '') {
            $errors[] = 'Brak aktywnej loterii zdrapkowej.';
        }

        $sellerDefinition = $this->resolveModuleDefinition('sprzedawcy');
        $sellerTable = $sellerDefinition['database']['table'] ?? null;
        $sellerTicketDefinition = $this->resolveModuleDefinition('sprzedawcy_losy');
        $sellerTicketTable = $sellerTicketDefinition['database']['table'] ?? null;

        if (!is_string($sellerTable) || $sellerTable === '' || !is_string($sellerTicketTable) || $sellerTicketTable === '') {
            $errors[] = 'Brak konfiguracji modułów sprzedawców.';
        }

        $_SESSION['seller_tickets_check_form_data'] = [
            'email' => $email,
            'kod_sprzedawcy' => $sellerCode,
        ];

        if ($errors !== []) {
            $_SESSION['seller_tickets_check_errors'] = $errors;
            $_SESSION['seller_tickets_check'] = null;

            return new RedirectResponse($this->buildSellerRegistrationUrl($centerContextId, $sellerCenters));
        }

        $connection = $this->entityManager->getConnection();
        $seller = $connection->createQueryBuilder()
            ->select('id')
            ->from($sellerTable)
            ->where('email = :email')
            ->andWhere('kod_sprzedawcy = :sellerCode')
            ->andWhere('loteria = :lotteryId')
            ->setParameter('email', $email)
            ->setParameter('sellerCode', $sellerCode)
            ->setParameter('lotteryId', $currentLotteryId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!$seller) {
            $_SESSION['seller_tickets_check_errors'] = ['Podane konto nie istnieje.'];
            $_SESSION['seller_tickets_check'] = null;

            return new RedirectResponse($this->buildSellerRegistrationUrl($centerContextId, $sellerCenters));
        }

        $ticketCount = (int) $connection->createQueryBuilder()
            ->select('COUNT(id)')
            ->from($sellerTicketTable)
            ->where('sprzedawca = :sellerId')
            ->andWhere('loteria = :lotteryId')
            ->setParameter('sellerId', (int) ($seller['id'] ?? 0))
            ->setParameter('lotteryId', $currentLotteryId)
            ->executeQuery()
            ->fetchOne();

        $_SESSION['seller_tickets_check_errors'] = [];
        $_SESSION['seller_tickets_check'] = [
            'email' => $email,
            'kod_sprzedawcy' => $sellerCode,
            'ticket_count' => $ticketCount,
        ];

        return new RedirectResponse($this->buildSellerRegistrationUrl($centerContextId, $sellerCenters));
    }

    public function submitLosowaneZdrapki(Request $request): Response
    {
        if (($ageGateResponse = $this->requireAgeVerification($request)) !== null) {
            return $ageGateResponse;
        }

        return $this->submitLotteryRegistration(
            $request,
            'losowanie_zdrapki',
            'frontend/forms/zywiec_lottery_response.html.twig'
        );
    }

    private function lotteryCodeCanBeUsedInZywiecModule(string $code): bool
    {
        $normalizedCode = trim($code);
        if ($normalizedCode === '') {
            return false;
        }

        return $this->zywiecCodeCanBeUsed($normalizedCode, 'wykorzystany_do_loterii');
    }

    private function zywiecCodeCanBeUsed(string $code, string $usedColumn): bool
    {
        return $this->resolveZywiecCodeUsageStatus($code, $usedColumn)['status'] === 'available';
    }

    /**
     * @return array{status: 'available'|'used'|'missing', id: ?int}
     */
    private function resolveZywiecCodeUsageStatus(string $code, string $usedColumn): array
    {
        $normalizedCode = trim($code);
        if ($normalizedCode === '' || $usedColumn !== 'wykorzystany_do_loterii') {
            return ['status' => 'missing', 'id' => null];
        }
        $definition = $this->resolveModuleDefinition('kody_zywiec');
        $table = $definition['database']['table'] ?? null;
        if (!is_string($table) || $table === '') {
            return ['status' => 'missing', 'id' => null];
        }
        $codeEntry = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('id', $usedColumn)
            ->from($table)
            ->where($this->buildCaseSensitiveZywiecCodeCondition('kod'))
            ->setParameter('code', $normalizedCode)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($codeEntry)) {
            return ['status' => 'missing', 'id' => null];
        }

        $id = (int) ($codeEntry['id'] ?? 0);
        $usedValue = $codeEntry[$usedColumn] ?? null;
        $isUsed = !($usedValue === null || $usedValue === '' || (string) $usedValue === '0' || $usedValue === 0 || $usedValue === false);

        return [
            'status' => $isUsed ? 'used' : 'available',
            'id' => $id > 0 ? $id : null,
        ];
    }

    private function buildCaseSensitiveZywiecCodeCondition(string $column): string
    {
        $platformName = strtolower($this->entityManager->getConnection()->getDatabasePlatform()->getName());
        if (str_contains($platformName, 'mysql') || str_contains($platformName, 'mariadb')) {
            return 'BINARY ' . $column . ' = :code';
        }
        if (str_contains($platformName, 'sqlite')) {
            return $column . ' COLLATE BINARY = :code';
        }

        return $column . ' = :code';
    }

    private function markLotteryCodeAsUsedInZywiecModule(string $code, \DateTimeInterface $usedAt): void
    {
        $this->markZywiecCodeAsUsed($code, $usedAt, 'wykorzystany_do_loterii', 'data_wykorzystania_do_loterii');
    }

    private function markZywiecCodeAsUsed(string $code, \DateTimeInterface $usedAt, string $usedColumn, string $dateColumn): void
    {
        $normalizedCode = trim($code);
        if ($normalizedCode === '' || $usedColumn !== 'wykorzystany_do_loterii') return;
        if ($dateColumn !== 'data_wykorzystania_do_loterii') return;
        $definition = $this->resolveModuleDefinition('kody_zywiec');
        $table = $definition['database']['table'] ?? null;
        if (!is_string($table) || $table === '') return;
        $this->entityManager->getConnection()->createQueryBuilder()
            ->update($table)
            ->set($usedColumn, ':used')
            ->set($dateColumn, ':usedAt')
            ->where($this->buildCaseSensitiveZywiecCodeCondition('kod'))
            ->setParameter('used', true)
            ->setParameter('usedAt', $usedAt->format('Y-m-d H:i:s'))
            ->setParameter('code', $normalizedCode)
            ->executeStatement();
    }

    private function submitLotteryRegistration(Request $request, string $requiredLotteryType, string $sectionTemplate): Response
    {
        $data = $request->request->all();
        $isAjax = $request->isXmlHttpRequest();
        $ajaxOutputBufferLevel = null;
        $lotterySubmissionLockNames = [];
        if ($isAjax) {
            $ajaxOutputBufferLevel = ob_get_level();
            ob_start();
        }

        try {
        $errors = [];
        $errorsByField = [];
        $addError = static function (string $field, string $message) use (&$errors, &$errorsByField): void {
            $errors[] = $message;
            $errorsByField[] = [
                'field' => $field,
                'message' => $message,
            ];
        };
        $submittedToken = trim((string) ($data['lottery_form_token'] ?? ''));
        if (!$this->consumeLotteryFormToken($submittedToken)) {
            $addError('lottery_form_token', 'Sesja formularza wygasła lub formularz został już wysłany. Odśwież stronę i spróbuj ponownie.');
        }
        $settings = $this->fetchModuleEntry('settings') ?? [];
        $lotteryRulesContent = $this->resolveRulesContent($this->fetchModuleEntries('teksty_na_stronie')) ?? [];
        $rulesFileLink = (string) ($lotteryRulesContent['formularz_regulamin_plik'] ?? '');
        $rulesAcceptedImage = (string) ($lotteryRulesContent['formularz_regulamin_zdjecie'] ?? '');
        $formBottomText = (string) ($lotteryRulesContent['formularz_tekst_dol'] ?? '');
        $lotteries = $this->fetchActiveLotteriesByType($requiredLotteryType);
        $lotterySettings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
        $currentLottery = $this->resolveCurrentLottery($lotterySettings);
        $lotteryCenters = $this->resolveLotteryCenters($currentLottery);
        $storeOptionsByCenter = $this->resolveStoreOptionsByCenter($lotteryCenters);
        $allowedCenterIds = array_column($lotteryCenters, 'id');

        $isCashbackLottery = $requiredLotteryType === 'cashback';

        $requiredFields = [
            'seller_code' => 'Kod jest wymagany.',
            'email' => 'Adres e-mail jest wymagany.',
            'phone' => 'Numer telefonu jest wymagany.',
        ];

        $storeChains = $this->fetchModuleEntries('sieci_sklepow', ['active' => 1]);
        foreach (LotteryRegistrationValidator::validatePurchaseData($data, array_column($storeChains, 'id')) as $field => $message) {
            $addError($field, $message);
        }

        foreach ($requiredFields as $field => $message) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value === '') {
                $addError($field, $message);
            }
        }

        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $addError('email', 'Adres e-mail ma nieprawidłowy format.');
        } elseif ($email !== '' && ($suggestedDomain = $this->resolveSuggestedEmailDomain($email)) !== null && !$this->isSuggestedEmailDomainConfirmed($data, $email)) {
            $this->rememberSuggestedEmailDomainWarning($email);
            $data['email_domain_warning_ack'] = $email;
            $addError('email', sprintf('Sprawdź domenę adresu e-mail. Czy chodziło o %s? Jeśli adres jest poprawny, wyślij formularz ponownie.', $suggestedDomain));
        } elseif ($email !== '' && $this->isEmailAssignedToConfirmedSellerAccount($email)) {
            $addError('email', 'Podany adres e-mail nie może brać udziału w loterii.');
        }

        $phone = LotteryRegistrationValidator::normalizePhone(trim((string) ($data['phone'] ?? '')));
        $data['phone'] = $phone;
        if ($phone !== '' && !preg_match(LotteryRegistrationValidator::MOBILE_PHONE_PATTERN, $phone)) {
            $addError('phone', 'Wpisz dziewięciocyfrowy numer telefonu komórkowego z polskich zasobów numeracji.');
        }
        $data['store_nip'] = LotteryRegistrationValidator::normalizeNip(is_scalar($data['store_nip'] ?? '') ? (string) $data['store_nip'] : '');

        $amountRaw = trim((string) ($data['purchase_amount'] ?? ''));
        $normalizedAmount = str_replace(',', '.', $amountRaw);
        if ($normalizedAmount !== '' && !preg_match('/^\d+(?:\.\d{2})$/', $normalizedAmount)) {
            $addError('purchase_amount', 'Kwota zakupu musi być podana z groszami (np. 123.45).');
        }

        $purchaseReceiptFile = $request->files->get('purchase_receipt');
        if (!$purchaseReceiptFile instanceof UploadedFile) {
            $addError('purchase_receipt', 'Dołączenie dowodu zakupu jest wymagane.');
        } elseif (!$purchaseReceiptFile->isValid()) {
            $addError('purchase_receipt', 'Nie udało się przesłać dowodu zakupu. Spróbuj ponownie.');
        } elseif (!$this->isLotteryReceiptUploadAllowed($purchaseReceiptFile)) {
            $addError('purchase_receipt', 'Dozwolone są pliki JPG, PNG lub PDF o maksymalnym rozmiarze 15 MB.');
        }

        if (($data['not_excluded'] ?? '') !== 'yes') {
            $addError('not_excluded', 'Potwierdzenie braku wyłączenia z udziału w Loterii jest wymagane.');
        }

        if (($data['rules_accepted'] ?? '') !== 'yes') {
            $addError('rules_accepted', 'Akceptacja regulaminu jest wymagana.');
        }

        $selectedCenterId = trim((string) ($data['centrum_handlowe'] ?? ''));
        if (!in_array($selectedCenterId, $allowedCenterIds, true)) {
            $selectedCenterId = (string) ($allowedCenterIds[0] ?? '');
            $data['centrum_handlowe'] = $selectedCenterId;
        }

        $sellerCode = trim((string) ($data['seller_code'] ?? ''));
        $isGiftCardPurchase = $isCashbackLottery;

        if ($isCashbackLottery) {
            $isGiftCardPurchase = true;
            $sellerCode = '';
        } else {
            $isGiftCardPurchase = false;
        }

        if ($errors === []) {
            $recaptchaError = $this->validateRecaptcha(
                $data['g-recaptcha-response'] ?? null,
                $request->getClientIp()
            );
            if ($recaptchaError !== null) {
                $addError('recaptcha', $recaptchaError);
            }
        }

        if ($currentLottery === null) {
            $addError('lottery', 'Brak aktywnej loterii do zgłoszenia.');
        }

        $definition = $this->resolveModuleDefinition('loteria_zgloszenia');
        $table = $definition['database']['table'] ?? null;
        $validatedSellerEntry = null;
        $validatedLotteryCodeId = null;

        if ($errors === [] && is_string($table) && $table !== '') {
            try {
                LotteryReceiptValidator::assertAvailable($this->entityManager->getConnection(), $table, $data);
            } catch (LotteryDuplicateReceiptException $exception) {
                $addError('purchase_document_number', $exception->getMessage());
            }
        }

        if ($errors === [] && $sellerCode !== '' && !isset($errorsByField['seller_code'])) {
            $sellerCodeStatus = $this->resolveZywiecCodeUsageStatus($sellerCode, 'wykorzystany_do_loterii');
            if ($sellerCodeStatus['status'] === 'missing') {
                $addError('seller_code', 'Podany kod jest niepoprawny.');
            } elseif ($sellerCodeStatus['status'] === 'used') {
                $addError('seller_code', 'Ten kod został już zarejestrowany w loterii.');
            } elseif ($sellerCodeStatus['id'] === null) {
                $addError('seller_code', 'Nie udało się zapisać podanego kodu. Spróbuj ponownie.');
            } else {
                $validatedLotteryCodeId = $sellerCodeStatus['id'];
            }
        }

        $enforceEmailPhoneOneToOne = filter_var(
            $lotterySettings['enforce_email_phone_one_to_one'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $limitOneSubmissionPerEmailPerDay = filter_var(
            $lotterySettings['limit_one_submission_per_email_per_day'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        if (
            $errors === []
            && is_string($table)
            && $table !== ''
            && ($enforceEmailPhoneOneToOne || $limitOneSubmissionPerEmailPerDay)
        ) {
            $requestedLockNames = ['lottery-email-' . substr(hash('sha256', $email), 0, 40)];
            if ($enforceEmailPhoneOneToOne) {
                $requestedLockNames[] = 'lottery-phone-' . substr(hash('sha256', $phone), 0, 40);
            }

            $acquiredLockNames = $this->acquireLotterySubmissionLocks($requestedLockNames);
            if ($acquiredLockNames === null) {
                $addError('lottery', 'Nie udało się teraz zweryfikować limitów zgłoszeń. Spróbuj ponownie za chwilę.');
            } else {
                $lotterySubmissionLockNames = $acquiredLockNames;
            }
        }

        if ($errors === [] && $enforceEmailPhoneOneToOne && is_string($table) && $table !== '') {
            $identityConflicts = $this->findLotteryEmailPhoneIdentityConflicts($table, $email, $phone);
            if ($identityConflicts['email']) {
                $addError('email', 'Ten adres e-mail jest już powiązany z innym numerem telefonu.');
            }
            if ($identityConflicts['phone']) {
                $addError('phone', 'Ten numer telefonu jest już powiązany z innym adresem e-mail.');
            }
        }

        if (
            $errors === []
            && $limitOneSubmissionPerEmailPerDay
            && is_string($table)
            && $table !== ''
            && $this->hasLotterySubmissionForEmailToday($table, $email)
        ) {
            $addError('email', 'Z tego adresu e-mail wysłano już dzisiaj zgłoszenie. Kolejne zgłoszenie będzie możliwe jutro.');
        }

        if ($errors !== []) {
            $this->logLotteryFormValidationFailure($request, $requiredLotteryType, $data, $errors, $errorsByField);

            if ($isAjax) {
                $sectionHtml = $this->twig->render($sectionTemplate, [
                    'lotteries' => $lotteries,
                    'currentLottery' => $currentLottery,
                    'lotteryCenters' => $lotteryCenters,
                    'storeOptionsByCenter' => $storeOptionsByCenter,
                    'lotteryErrors' => $errors,
                    'lotteryFormData' => $data,
                    'lotterySubmission' => null,
                    'lotteryFormToken' => $this->issueLotteryFormToken(),
                    'formBottomText' => $formBottomText,
                    'rulesAcceptedImage' => $rulesAcceptedImage,
                    'rulesFileLink' => $rulesFileLink,
                ]);

                return new JsonResponse([
                    'success' => false,
                    'html' => $sectionHtml,
                    'errorsByField' => $errorsByField,
                ], 422);
            }

            $currentLottery = $currentLottery ?? $this->resolveCurrentLottery($this->fetchModuleEntry('loteria_ustawienia') ?? []);
            $allCenters = $this->fetchActiveShoppingCenters();
            $selectedCenter = $this->resolveCenterById($allCenters, $selectedCenterId);

            return $this->render($this->resolveRegistrationPageTemplate($settings), [
                'settings' => $settings,
                'lotteryRulesContent' => $lotteryRulesContent,
                'centers' => $allCenters,
                'centerUrls' => [],
                'selectedCenterId' => $selectedCenterId,
                'selectedCenter' => $selectedCenter,
                'sellerCenterUrl' => $this->buildSellerRegistrationUrl($selectedCenterId, $allCenters),
                'lotteries' => $lotteries,
                'currentLottery' => $currentLottery,
                'lotteryCenters' => $lotteryCenters,
                'storeOptionsByCenter' => $storeOptionsByCenter,
                'submission' => null,
                'lotterySubmission' => null,
                'lotteryErrors' => $errors,
                'lotteryFormData' => $data,
                'lotteryFormToken' => $this->issueLotteryFormToken(),
                'submissionTemplate' => $this->resolveSubmissionTemplateForLotteryType($requiredLotteryType),
            ]);
        }

        $submittedAt = new \DateTimeImmutable();
        $lotteryId = '';
        if ($currentLottery !== null) {
            $lotteryId = trim((string) ($currentLottery['id'] ?? ''));
            if ($lotteryId !== '') {
                $data['loteria'] = $lotteryId;
            }
        }

        $submissionId = null;
        $scratchLinkForEmail = '';
        $ticketCountForSubmissionEmail = 0;
        $totalTicketCountForEmail = 0;
        $generatedTicketIds = [];
        $generatedScratchIds = [];
        $dailyAllocation = ['tickets' => 0, 'scratchcards' => 0, 'limited' => false];
        $entryGenerationMode = $this->resolveLotteryEntryGenerationMode($lotterySettings);
        $shouldGenerateTickets = $isCashbackLottery
            ? true
            : $this->shouldGenerateLotteryTickets($entryGenerationMode);
        $shouldGenerateScratchcards = !$isCashbackLottery
            && $this->shouldGenerateLotteryScratchcards($entryGenerationMode);
        $receiptPath = null;

        if ($purchaseReceiptFile instanceof UploadedFile) {
            $receiptPath = $this->storeLotteryReceipt($purchaseReceiptFile, 'loteria_zgloszenia');
            if ($receiptPath === null) {
                $addError('purchase_receipt', 'Nie udało się zapisać dowodu zakupu.');
            }
        }

        if ($errors !== []) {
            $this->logLotteryFormValidationFailure($request, $requiredLotteryType, $data, $errors, $errorsByField);

            if ($isAjax) {
                $sectionHtml = $this->twig->render($sectionTemplate, [
                    'lotteries' => $lotteries,
                    'currentLottery' => $currentLottery,
                    'lotteryCenters' => $lotteryCenters,
                    'storeOptionsByCenter' => $storeOptionsByCenter,
                    'lotteryErrors' => $errors,
                    'lotteryFormData' => $data,
                    'lotterySubmission' => null,
                    'lotteryFormToken' => $this->issueLotteryFormToken(),
                    'formBottomText' => $formBottomText,
                    'rulesAcceptedImage' => $rulesAcceptedImage,
                    'rulesFileLink' => $rulesFileLink,
                ]);

                return new JsonResponse([
                    'success' => false,
                    'html' => $sectionHtml,
                    'errorsByField' => $errorsByField,
                ], 422);
            }

            $allCenters = $this->fetchActiveShoppingCenters();
            $selectedCenter = $this->resolveCenterById($allCenters, $selectedCenterId);

            return $this->render($this->resolveRegistrationPageTemplate($settings), [
                'settings' => $settings,
                'lotteryRulesContent' => $lotteryRulesContent,
                'centers' => $allCenters,
                'centerUrls' => [],
                'selectedCenterId' => $selectedCenterId,
                'selectedCenter' => $selectedCenter,
                'sellerCenterUrl' => $this->buildSellerRegistrationUrl($selectedCenterId, $allCenters),
                'lotteries' => $lotteries,
                'currentLottery' => $currentLottery,
                'lotteryCenters' => $lotteryCenters,
                'storeOptionsByCenter' => $storeOptionsByCenter,
                'submission' => null,
                'lotterySubmission' => null,
                'lotteryErrors' => $errors,
                'lotteryFormData' => $data,
                'lotteryFormToken' => $this->issueLotteryFormToken(),
                'submissionTemplate' => $this->resolveSubmissionTemplateForLotteryType($requiredLotteryType),
            ]);
        }

        if ($table) {
            $connection = $this->entityManager->getConnection();
            $scratchToken = '';
            $ipAddress = $request->getClientIp();
            $userAgent = $request->headers->get('User-Agent');
            $phone = trim((string) ($data['phone'] ?? ''));
            $validSellerCode = false;
            $sellerEntry = is_array($validatedSellerEntry) ? $validatedSellerEntry : null;
            if (!$isGiftCardPurchase && $sellerCode !== '') {
                if (!is_array($sellerEntry)) {
                    $sellerDefinition = $this->resolveModuleDefinition('sprzedawcy');
                    $sellerTable = $sellerDefinition['database']['table'] ?? null;
                    if (is_string($sellerTable) && $sellerTable !== '') {
                        $sellerEntry = $connection->createQueryBuilder()
                            ->select('*')
                            ->from($sellerTable)
                            ->where('kod_sprzedawcy = :sellerCode')
                            ->setParameter('sellerCode', $sellerCode)
                            ->setMaxResults(1)
                            ->executeQuery()
                            ->fetchAssociative();
                    }
                }

                if (is_array($sellerEntry) && isset($sellerEntry['id']) && (int) ($sellerEntry['id'] ?? 0) > 0) {
                    $sellerCenterId = trim((string) ($sellerEntry['centrum_handlowe'] ?? ''));
                    $validSellerCode = $sellerCenterId !== '' && $sellerCenterId === $selectedCenterId;
                }
            }

            $settingsEntry = $lotterySettings;
            $amountPerTicket = 50.0;
            if (isset($settingsEntry['amount_per_ticket']) && is_numeric($settingsEntry['amount_per_ticket'])) {
                $amountPerTicket = (float) $settingsEntry['amount_per_ticket'];
            }
            if ($amountPerTicket <= 0) {
                $amountPerTicket = 50.0;
            }

            $purchaseAmount = $normalizedAmount !== '' ? (float) $normalizedAmount : ($isCashbackLottery ? 0.0 : $amountPerTicket);
            $ticketMultiplier = ($isGiftCardPurchase && !$isCashbackLottery) ? 2 : 1;
            $baseTicketCount = (int) floor($purchaseAmount / $amountPerTicket) * $ticketMultiplier;
            $ticketCount = $baseTicketCount;
            if ($validSellerCode && $baseTicketCount > 0) {
                $ticketCount += 1;
            }
            if (isset($settingsEntry['max_tickets_per_receipt']) && is_numeric($settingsEntry['max_tickets_per_receipt'])) {
                $maxTicketsPerReceipt = (int) $settingsEntry['max_tickets_per_receipt'];
                if ($maxTicketsPerReceipt > 0) {
                    $ticketCount = min($ticketCount, $maxTicketsPerReceipt);
                }
            }
            $ticketDefinition = $this->resolveModuleDefinition('loteria_losy');
            $ticketTable = $ticketDefinition ? ($ticketDefinition['database']['table'] ?? null) : null;
            $sellerTicketDefinition = $this->resolveModuleDefinition('sprzedawcy_losy');
            $sellerTicketTable = $sellerTicketDefinition ? ($sellerTicketDefinition['database']['table'] ?? null) : null;
            $scratchDefinition = $this->resolveModuleDefinition('loteria_zdrapki');
            $scratchTable = $scratchDefinition ? ($scratchDefinition['database']['table'] ?? null) : null;

            try {
                if (LotteryProductAllocation::isProductBased($settingsEntry)) {
                    $baseTicketCount = LotteryProductAllocation::requestedEntryCount($data, $settingsEntry);
                    $ticketCount = $baseTicketCount;
                }
                $dailyAllocation = (new LotteryDailyEntryLimitService($connection, $this->config['app']['timezone'] ?? 'Europe/Warsaw'))->allocate(
                    $settingsEntry,
                    ['tickets' => $ticketTable, 'scratchcards' => $scratchTable],
                    [
                        'tickets' => $ticketTable && $shouldGenerateTickets ? $ticketCount : 0,
                        'scratchcards' => $scratchTable && $shouldGenerateScratchcards ? $ticketCount : 0,
                    ],
                    $submittedAt,
                    ['email' => $email, 'phone' => $phone],
                    function (array $counts) use (
                        $connection, $table, $email, $phone, $data, $normalizedAmount, $receiptPath,
                        $isGiftCardPurchase, $validatedLotteryCodeId, $ipAddress, $userAgent, $submittedAt,
                        &$scratchToken, $sellerCode, $ticketTable, $scratchTable, $settingsEntry,
                        &$submissionId, &$generatedTicketIds, &$generatedScratchIds
                    ): void {
                        $scratchToken = $this->resolveScratchcardsTokenForEmail($connection, (string) $table, $email);
                        $connection->insert($table, [
                            'email' => $email,
                            'phone' => $phone,
                            'purchase_document_number' => trim((string) ($data['purchase_document_number'] ?? '')),
                            'promoted_products_count' => (int) $data['promoted_products_count'],
                            'other_products_count' => (int) $data['other_products_count'],
                            'allocated_promoted_products_count' => $counts['allocated_promoted_products_count'],
                            'allocated_other_products_count' => $counts['allocated_other_products_count'],
                            'store_chain' => (int) $data['store_chain'],
                            'store_nip' => $data['store_nip'],
                            'purchase_amount' => $normalizedAmount !== '' ? (float) $normalizedAmount : null,
                            'rachunek' => $receiptPath,
                            'rules_accepted' => true,
                            'not_excluded' => true,
                            'seller_code' => (!$isGiftCardPurchase && $validatedLotteryCodeId !== null) ? $validatedLotteryCodeId : null,
                            'ip_address' => $ipAddress ?: null,
                            'user_agent' => $userAgent ?: null,
                            'submitted_at' => $submittedAt->format('Y-m-d H:i:s'),
                            'scratch_link_token' => $scratchToken,
                            'reminder_sent_at' => null,
                        ]);
                        $submissionId = (int) $connection->lastInsertId();
                        if (!$isGiftCardPurchase && $sellerCode !== '') {
                            $this->markLotteryCodeAsUsedInZywiecModule($sellerCode, $submittedAt);
                        }
                        $this->createLotteryEntriesWithIdentifiers(
                            $connection,
                            array_values(array_filter([$ticketTable, $scratchTable], static fn(mixed $table): bool => is_string($table) && $table !== '')),
                            $submissionId,
                            max($counts['tickets'], $counts['scratchcards']),
                            $settingsEntry,
                            function (string $ticketId, int $index) use (
                                $connection,
                                $ticketTable,
                                $scratchTable,
                                $counts,
                                $submissionId,
                                $email,
                                $phone,
                                $submittedAt,
                                &$generatedTicketIds,
                                &$generatedScratchIds
                            ): void {
                                if ($ticketTable && $index <= $counts['tickets']) {
                                    $generatedTicketIds[] = $ticketId;
                                    $connection->insert($ticketTable, [
                                        'ticket_id' => $ticketId,
                                        'zgloszenie' => $submissionId,
                                        'email' => $email,
                                        'phone' => $phone,
                                        'submitted_at' => $submittedAt->format('Y-m-d H:i:s'),
                                        'wygrany' => 0,
                                        'status' => 'nie_sprawdzany',
                                    ]);
                                }
                                if ($scratchTable && $index <= $counts['scratchcards']) {
                                    $generatedScratchIds[] = $ticketId;
                                    $connection->insert($scratchTable, [
                                        'ticket_id' => $ticketId,
                                        'zgloszenie' => $submissionId,
                                        'email' => $email,
                                        'phone' => $phone,
                                        'submitted_at' => $submittedAt->format('Y-m-d H:i:s'),
                                        'wygrany' => 0,
                                        'status' => 'Nie zdrapana',
                                    ]);
                                }
                            }
                        );
                    },
                    null,
                    [
                        'table' => $table,
                        'promoted_products_count' => (int) $data['promoted_products_count'],
                        'other_products_count' => (int) $data['other_products_count'],
                    ],
                    [
                        'table' => $table,
                        'purchase_document_number' => (string) $data['purchase_document_number'],
                        'store_nip' => $data['store_nip'],
                    ]
                );
            } catch (LotteryDailyLimitException $exception) {
                $this->discardLotteryReceipt($receiptPath);
                $addError($exception instanceof LotteryDuplicateReceiptException ? 'purchase_document_number' : 'lottery', $exception->getMessage());
                $this->logLotteryFormValidationFailure($request, $requiredLotteryType, $data, $errors, $errorsByField);
                if ($isAjax) {
                    return new JsonResponse([
                        'success' => false,
                        'errorsByField' => $errorsByField,
                        'html' => $this->twig->render($sectionTemplate, ['lotteryFormToken' => $this->issueLotteryFormToken()]),
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                return $this->render($this->resolveRegistrationPageTemplate($settings), [
                    'settings' => $settings,
                    'lotteryRulesContent' => $lotteryRulesContent,
                    'currentLottery' => $currentLottery,
                    'lotteryFormData' => $data,
                    'lotteryErrors' => $errors,
                    'lotteryFormToken' => $this->issueLotteryFormToken(),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            } catch (\Throwable $exception) {
                $this->discardLotteryReceipt($receiptPath);
                throw $exception;
            }
            $this->releaseLotterySubmissionLocks($lotterySubmissionLockNames);
            $lotterySubmissionLockNames = [];

            $ticketCountForSubmissionEmail = $shouldGenerateTickets ? count($generatedTicketIds) : count($generatedScratchIds);
            if ($generatedScratchIds !== []) {
                $scratchLinkForEmail = $this->buildScratchcardsLink($request, $scratchToken);
            }

            if ($ticketTable && $shouldGenerateTickets) {
                $totalTicketCountForEmail = (int) $connection->createQueryBuilder()
                    ->select('COUNT(*)')
                    ->from($ticketTable)
                    ->where('email = :email')
                    ->setParameter('email', $email)
                    ->executeQuery()
                    ->fetchOne();
            } elseif ($scratchTable && $shouldGenerateScratchcards) {
                $totalTicketCountForEmail = (int) $connection->createQueryBuilder()
                    ->select('COUNT(*)')
                    ->from($scratchTable)
                    ->where('email = :email')
                    ->setParameter('email', $email)
                    ->executeQuery()
                    ->fetchOne();
            }

            if ($totalTicketCountForEmail <= 0) {
                $totalTicketCountForEmail = $ticketCountForSubmissionEmail;
            }

            if ($sellerTicketTable && $validSellerCode && $baseTicketCount > 0 && $sellerEntry !== null && $submissionId > 0) {
                $sellerTicketId = (string) $submissionId;
                $connection->insert($sellerTicketTable, [
                    'ticket_id' => $sellerTicketId,
                    'zgloszenie' => $submissionId,
                    'sprzedawca' => (int) ($sellerEntry['id'] ?? 0),
                    'email' => $email,
                    'phone' => $phone,
                    'seller_email' => trim((string) ($sellerEntry['email'] ?? '')),
                    'seller_phone' => trim((string) ($sellerEntry['telefon'] ?? '')),
                    'seller_code' => $sellerCode,
                    'submitted_at' => $submittedAt->format('Y-m-d H:i:s'),
                    'wygrany' => 0,
                    'status' => 'nie_sprawdzany',
                ]);
            }
        }

        try {
            $this->sendLotterySubmissionEmail(
                $email,
                $data,
                $submittedAt,
                $scratchLinkForEmail,
                $ticketCountForSubmissionEmail,
                $totalTicketCountForEmail,
                count($generatedScratchIds)
            );
        } catch (\Throwable $exception) {
            // E-mail errors must not turn an already saved submission into a frontend failure.
        }

        $generatedEntryIds = $generatedScratchIds !== [] ? $generatedScratchIds : $generatedTicketIds;

        $lotterySubmission = [
            'form' => $data,
            'submission_id' => $submissionId,
            'generation_mode' => $entryGenerationMode,
            'scratchcards_enabled' => $shouldGenerateScratchcards && $generatedScratchIds !== [],
            'granted_ticket_count' => count($generatedTicketIds),
            'granted_scratchcard_count' => count($generatedScratchIds),
            'daily_limit_applied' => $dailyAllocation['limited'],
            'allocated_promoted_products_count' => $dailyAllocation['allocated_promoted_products_count'] ?? 0,
            'allocated_other_products_count' => $dailyAllocation['allocated_other_products_count'] ?? 0,
            'message' => $dailyAllocation['limited']
                ? sprintf('Zgłoszenie zostało zapisane. Ze względu na dzienny limit przyznano %d losów i %d e-zdrapek.', count($generatedTicketIds), count($generatedScratchIds))
                : 'Dziękujemy! Twoje zgłoszenie zostało przyjęte.',
            'ticket_ids' => $generatedEntryIds,
            'scratchcard_ids' => $generatedScratchIds,
            'ticket_entries' => array_map(static function (string $ticketId) use ($submissionId): array {
                return [
                    'ticket_id' => $ticketId,
                    'submission_id' => $submissionId,
                ];
            }, $generatedEntryIds),
            'ticket_count' => count($generatedEntryIds),
            'scratch_token' => $scratchToken,
            'scratch_link' => $scratchLinkForEmail,
        ];

        if ($isAjax) {
            $sectionHtml = $this->twig->render($sectionTemplate, [
                'lotteries' => $lotteries,
                'currentLottery' => $currentLottery,
                'lotteryCenters' => $lotteryCenters,
                'storeOptionsByCenter' => $storeOptionsByCenter,
                'lotterySubmission' => $lotterySubmission,
                'lotteryFormData' => $data,
                'lotteryErrors' => [],
                'lotteryFormToken' => $this->issueLotteryFormToken(),
                'formBottomText' => $formBottomText,
                'rulesAcceptedImage' => $rulesAcceptedImage,
                'rulesFileLink' => $rulesFileLink,
            ]);

            return new JsonResponse([
                'success' => true,
                'html' => $sectionHtml,
                'submission' => $lotterySubmission,
            ]);
        }

        $_SESSION['lottery_submission'] = $lotterySubmission;

        return new RedirectResponse('/loteria');
        } finally {
            $this->releaseLotterySubmissionLocks($lotterySubmissionLockNames);
            if ($ajaxOutputBufferLevel !== null) {
                while (ob_get_level() > $ajaxOutputBufferLevel) {
                    ob_end_clean();
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $errors
     * @param array<int, array{field: string, message: string}> $errorsByField
     */
    private function logLotteryFormValidationFailure(Request $request, string $lotteryType, array $data, array $errors, array $errorsByField): void
    {
        $definition = $this->resolveModuleDefinition('logi_formularza_zgloszeniowego');
        $table = $definition['database']['table'] ?? null;
        if (!is_string($table) || $table === '') {
            return;
        }

        $connection = $this->entityManager->getConnection();
        if (!$connection->createSchemaManager()->tablesExist([$table])) {
            return;
        }

        $now = new \DateTimeImmutable();
        $submittedEmail = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $submittedPhone = trim((string) ($data['phone'] ?? ''));
        $submittedCode = trim((string) ($data['seller_code'] ?? ''));
        $submittedDocumentNumber = trim((string) ($data['purchase_document_number'] ?? ''));
        $submittedAmount = trim((string) ($data['purchase_amount'] ?? ''));

        $safeData = $data;
        foreach (['g-recaptcha-response', 'lottery_form_token'] as $sensitiveField) {
            if (array_key_exists($sensitiveField, $safeData)) {
                $safeData[$sensitiveField] = '[usunięto z logu]';
            }
        }

        $headers = [
            'user_agent' => $request->headers->get('User-Agent'),
            'referer' => $request->headers->get('Referer'),
            'accept_language' => $request->headers->get('Accept-Language'),
            'x_forwarded_for' => $request->headers->get('X-Forwarded-For'),
            'x_real_ip' => $request->headers->get('X-Real-IP'),
            'host' => $request->headers->get('Host'),
            'origin' => $request->headers->get('Origin'),
        ];

        try {
            $connection->insert($table, [
                'created_at' => $now->format('Y-m-d H:i:s'),
                'lottery_type' => $lotteryType,
                'request_method' => $request->getMethod(),
                'request_uri' => $request->getRequestUri(),
                'ip_address' => $request->getClientIp(),
                'forwarded_for' => $request->headers->get('X-Forwarded-For'),
                'user_agent' => $request->headers->get('User-Agent'),
                'referer' => $request->headers->get('Referer'),
                'accept_language' => $request->headers->get('Accept-Language'),
                'session_id' => session_id() ?: null,
                'submitted_email' => $submittedEmail !== '' ? $submittedEmail : null,
                'submitted_phone' => $submittedPhone !== '' ? $submittedPhone : null,
                'submitted_code' => $submittedCode !== '' ? $submittedCode : null,
                'submitted_document_number' => $submittedDocumentNumber !== '' ? $submittedDocumentNumber : null,
                'submitted_amount' => $submittedAmount !== '' ? $submittedAmount : null,
                'errors' => json_encode($errorsByField !== [] ? $errorsByField : $errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'payload' => json_encode($safeData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'headers' => json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable $exception) {
            // Logowanie błędnych prób zgłoszenia nie może blokować odpowiedzi formularza dla użytkownika.
        }
    }

    public function revealRemainingScratchcards(Request $request, array $parameters): Response
    {
        if (($ageGateResponse = $this->requireAgeVerification($request)) !== null) {
            return $ageGateResponse;
        }

        $token = trim((string) ($parameters['token'] ?? ''));
        if ($token === '') {
            return new RedirectResponse('/loteria');
        }

        $submissionDefinition = $this->resolveModuleDefinition('loteria_zgloszenia');
        $scratchDefinition = $this->resolveModuleDefinition('loteria_zdrapki');
        $submissionTable = $submissionDefinition['database']['table'] ?? null;
        $scratchTable = $scratchDefinition['database']['table'] ?? null;

        if (!is_string($submissionTable) || $submissionTable === '' || !is_string($scratchTable) || $scratchTable === '') {
            return new RedirectResponse('/loteria');
        }

        $connection = $this->entityManager->getConnection();
        $scratchAccess = new LotteryScratchcardAccess($connection, $submissionTable, $scratchTable);
        if ($scratchAccess->emailForToken($token) === null) {
            return new RedirectResponse('/loteria');
        }
        $filteredTicketEntries = $scratchAccess->pendingEntries($token);
        $filteredTicketIds = array_column($filteredTicketEntries, 'ticket_id');

        $allCenters = [];
        $selectedCenterId = '';
        $selectedCenter = null;
        $lotterySettings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];

        $settings = $this->fetchModuleEntry('settings') ?? [];
        $currentLottery = $this->resolveDefaultScratchLottery();
        $upcomingLottery = $this->resolveUpcomingLottery($this->fetchModuleEntry('loteria_ustawienia') ?? []);
        $currentNewsletterLottery = $this->resolveCurrentLottery($lotterySettings);
        $lotteryCenters = [];
        $storeOptionsByCenter = [];
        $faqEntries = $this->resolveConsumerFaqEntries($settings);
        $textsOnSiteEntries = $this->fetchModuleEntries('teksty_na_stronie');
        $lotteryRulesContent = $this->resolveRulesContent($textsOnSiteEntries);
        $winnersPreview = $this->resolveFrontendWinnersPreview(5);
        $latestWinners = $winnersPreview['winners'];
        $hasMoreWinners = $winnersPreview['hasMore'];
        $scheduleDraws = $this->resolveFrontendScheduleDraws();

        $lotterySubmission = [
            'ticket_entries' => $filteredTicketEntries,
            'ticket_count' => count($filteredTicketIds),
            'scratchcards_enabled' => true,
            'scratch_token' => $token,
            'scratch_link' => $this->buildScratchcardsLink($request, $token),
            'from_email_link' => true,
            'message' => 'Twoje e-zdrapki',
        ];

        $response = $this->render($this->resolveRegistrationPageTemplate($settings), [
            'settings' => $settings,
            'centers' => $allCenters,
            'centerUrls' => [],
            'selectedCenterId' => $selectedCenterId,
            'selectedCenter' => $selectedCenter,
            'sellerCenterUrl' => $this->buildSellerRegistrationUrl($selectedCenterId, $allCenters),
            'lotteries' => [],
            'currentLottery' => $currentLottery,
            'upcomingLottery' => $upcomingLottery,
            'currentNewsletterLottery' => $currentNewsletterLottery,
            'lotteryCenters' => $lotteryCenters,
            'storeOptionsByCenter' => $storeOptionsByCenter,
            'lotterySubmission' => $lotterySubmission,
            'lotteryFormToken' => $this->issueLotteryFormToken(),
            'faqEntries' => $faqEntries,
            'lotteryRulesContent' => $lotteryRulesContent,
            'latestWinners' => $latestWinners,
            'hasMoreWinners' => $hasMoreWinners,
            'scheduleDraws' => $scheduleDraws,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }

    private function resolveRegistrationPageTemplate(?array $settings = null): string
    {
        if ($settings === null) {
            $settings = $this->fetchModuleEntry('settings') ?? [];
        }

        return 'frontend/zywiec.html.twig';
    }

    private function isSellerPageEnabled(?array $settings = null): bool
    {
        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveFrontendScheduleDraws(): array
    {
        $drawEntries = $this->fetchModuleEntries(
            'losowania',
            ['visible_on_page' => 1],
            ['orderBy' => 'drawing_date', 'direction' => 'asc']
        );

        $draws = [];
        foreach ($drawEntries as $entry) {
            $drawingDate = $this->createDateFromModuleValue($entry['drawing_date'] ?? null);
            if ($drawingDate === null) {
                continue;
            }

            $name = trim((string) ($entry['name'] ?? ''));
            $description = trim((string) ($entry['description'] ?? ''));
            $image = trim((string) ($entry['prize_image'] ?? ''));
            $winner = trim((string) ($entry['winner'] ?? ''));
            $fieldSize = (int) ($entry['field_size'] ?? 1);

            $draws[] = [
                'month' => $this->formatPolishMonthName($drawingDate),
                'day' => $drawingDate->format('d'),
                'weekday' => $this->formatPolishWeekdayName($drawingDate),
                'title' => $name !== '' ? $name : 'Losowanie',
                'description' => $description,
                'image' => $image,
                'winner' => $winner,
                'isWide' => $fieldSize === 2,
                'isDone' => $drawingDate < new \DateTimeImmutable('today'),
            ];
        }

        return $draws;
    }

    private function createDateFromModuleValue(mixed $value): ?\DateTimeImmutable
    {
        $dateValue = trim((string) $value);
        if ($dateValue === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($dateValue);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function formatPolishMonthName(\DateTimeImmutable $date): string
    {
        $months = [
            1 => 'Styczeń',
            2 => 'Luty',
            3 => 'Marzec',
            4 => 'Kwiecień',
            5 => 'Maj',
            6 => 'Czerwiec',
            7 => 'Lipiec',
            8 => 'Sierpień',
            9 => 'Wrzesień',
            10 => 'Październik',
            11 => 'Listopad',
            12 => 'Grudzień',
        ];

        return $months[(int) $date->format('n')] ?? $date->format('F');
    }

    private function formatPolishWeekdayName(\DateTimeImmutable $date): string
    {
        $weekdays = [
            1 => 'Poniedziałek',
            2 => 'Wtorek',
            3 => 'Środa',
            4 => 'Czwartek',
            5 => 'Piątek',
            6 => 'Sobota',
            7 => 'Niedziela',
        ];

        return $weekdays[(int) $date->format('N')] ?? $date->format('l');
    }

    private function resolveConsumerFaqEntries(array $settings): array
    {
        $faqEntries = $this->fetchModuleEntries(
            'faq',
            [],
            ['orderBy' => 'position', 'direction' => 'asc']
        );

        return array_values(array_filter(
            $faqEntries,
            fn (array $entry): bool => $this->isActiveModuleEntry($entry)
        ));
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<int, string>
     */
    private function resolveConsumerLotteryTypesForTemplate(array $settings): array
    {
        return ['losowanie_zdrapki'];
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchActiveConsumerLotteries(array $settings): array
    {
        $currentLottery = $this->resolveCurrentLottery($settings);
        if ($currentLottery === null) {
            return [];
        }

        $allowedTypes = $this->resolveConsumerLotteryTypesForTemplate($settings);
        $type = trim((string) ($currentLottery['type'] ?? ''));

        return in_array($type, $allowedTypes, true) ? [$currentLottery] : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchActiveLotteriesByType(string $lotteryType): array
    {
        return $this->fetchLotteriesByType($lotteryType, true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLotteriesByType(string $lotteryType, bool $activeOnly = false): array
    {
        $currentLottery = $this->resolveCurrentLottery($this->fetchModuleEntry('loteria_ustawienia') ?? []);
        if ($currentLottery === null) {
            return [];
        }

        return trim((string) ($currentLottery['type'] ?? '')) === $lotteryType ? [$currentLottery] : [];
    }

    private function resolveSubmissionTemplateForLotteryType(string $lotteryType): string
    {
        return 'frontend/forms/zywiec_lottery_response.html.twig';
    }


    private function isActiveModuleEntry(array $entry): bool
    {
        if (!array_key_exists('active', $entry)) {
            return true;
        }

        $active = $entry['active'];
        if ($active === null || $active === '') {
            return true;
        }

        return in_array(mb_strtolower(trim((string) $active)), ['1', 'true', 'yes', 'on'], true);
    }

    private function issueLotteryFormToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $tokens = $_SESSION[self::LOTTERY_FORM_TOKENS_SESSION_KEY] ?? [];
        if (!is_array($tokens)) {
            $tokens = [];
        }

        $tokens[$token] = time();
        if (count($tokens) > 20) {
            $tokens = array_slice($tokens, -20, null, true);
        }

        $_SESSION[self::LOTTERY_FORM_TOKENS_SESSION_KEY] = $tokens;

        return $token;
    }

    private function consumeLotteryFormToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $tokens = $_SESSION[self::LOTTERY_FORM_TOKENS_SESSION_KEY] ?? [];
        if (!is_array($tokens) || !isset($tokens[$token])) {
            return false;
        }

        unset($tokens[$token]);
        $_SESSION[self::LOTTERY_FORM_TOKENS_SESSION_KEY] = $tokens;

        return true;
    }

    public function remindUnrevealedScratchcards(Request $request): Response
    {
        $submissionDefinition = $this->resolveModuleDefinition('loteria_zgloszenia');
        $scratchDefinition = $this->resolveModuleDefinition('loteria_zdrapki');
        $submissionTable = $submissionDefinition['database']['table'] ?? null;
        $scratchTable = $scratchDefinition['database']['table'] ?? null;

        if (
            !is_string($submissionTable) || $submissionTable === ''
            || !is_string($scratchTable) || $scratchTable === ''
        ) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak konfiguracji modułów loterii.',
            ], 500);
        }

        $settings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
        $delayMinutes = (int) ($settings['scratchcards_reminder_delay_minutes'] ?? 10);
        if ($delayMinutes <= 0) {
            $delayMinutes = 10;
        }
        $threshold = (new \DateTimeImmutable())->sub(new \DateInterval(sprintf('PT%dM', $delayMinutes)));
        $now = new \DateTimeImmutable();
        $lotteryEndDate = $this->resolveLotteryEndDate($settings['loteria_data_zakonczenia'] ?? null);
        if ($lotteryEndDate !== null && $lotteryEndDate < $now) {
            return new JsonResponse([
                'success' => true,
                'sent' => 0,
                'checked' => 0,
            ]);
        }

        $connection = $this->entityManager->getConnection();

        $rows = $connection->createQueryBuilder()
            ->select('s.*')
            ->addSelect('COUNT(t.id) AS remaining_count')
            ->from($submissionTable, 's')
            ->innerJoin('s', $scratchTable, 't', 't.zgloszenie = s.id')
            ->where('s.submitted_at <= :threshold')
            ->andWhere('s.reminder_sent_at IS NULL')
            ->andWhere('t.win_checked_at IS NULL')
            ->groupBy('s.id')
            ->setParameter('threshold', $threshold->format('Y-m-d H:i:s'))
            ->executeQuery()
            ->fetchAllAssociative();

        $sentCount = 0;
        foreach ($rows as $submission) {
            $recipient = trim((string) ($submission['email'] ?? ''));
            if ($recipient === '') {
                continue;
            }

            $token = trim((string) ($submission['scratch_link_token'] ?? ''));
            if ($token === '') {
                $token = $this->resolveScratchcardsTokenForEmail($connection, $submissionTable, $recipient);
                $connection->update($submissionTable, [
                    'scratch_link_token' => $token,
                ], [
                    'id' => (int) $submission['id'],
                ]);
            }

            $scratchLink = $this->buildScratchcardsLink($request, $token);
            $sentAt = new \DateTimeImmutable();
            if ($this->sendLotteryReminderEmail($submission, $scratchLink, $sentAt)) {
                $connection->update($submissionTable, [
                    'reminder_sent_at' => $sentAt->format('Y-m-d H:i:s'),
                ], [
                    'id' => (int) $submission['id'],
                ]);
                $sentCount++;
            }
        }

        return new JsonResponse([
            'success' => true,
            'sent' => $sentCount,
            'checked' => count($rows),
        ]);
    }



    public function runConfiguredCronTasks(Request $request): Response
    {
        $connection = $this->entityManager->getConnection();
        $table = 'cron_entries';

        if (!$connection->createSchemaManager()->tablesExist([$table])) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Tabela CRON nie istnieje. Uruchom instalację/aktualizację DB w CMS.',
            ], 500);
        }

        $this->seedDefaultCronTasks($table);
        $cronColumns = array_keys($connection->createSchemaManager()->listTableColumns($table));

        $now = new \DateTimeImmutable();
        $rows = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where('enabled = :enabled')
            ->andWhere('(next_run_at IS NULL OR next_run_at <= :now OR last_run_at IS NULL)')
            ->andWhere('(running IS NULL OR running = 0 OR locked_at < :staleLock)')
            ->orderBy('next_run_at', 'ASC')
            ->setParameter('enabled', 1)
            ->setParameter('now', $now->format('Y-m-d H:i:s'))
            ->setParameter('staleLock', $now->sub(new \DateInterval('PT30M'))->format('Y-m-d H:i:s'))
            ->executeQuery()
            ->fetchAllAssociative();

        $results = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $claimed = $connection->createQueryBuilder()
                ->update($table)
                ->set('running', '1')
                ->set('locked_at', ':locked_at')
                ->where('id = :id')
                ->andWhere('(next_run_at IS NULL OR next_run_at <= :now OR last_run_at IS NULL)')
                ->andWhere('(running IS NULL OR running = 0 OR locked_at < :stale_lock)')
                ->setParameter('locked_at', $now->format('Y-m-d H:i:s'))
                ->setParameter('now', $now->format('Y-m-d H:i:s'))
                ->setParameter('stale_lock', $now->sub(new \DateInterval('PT30M'))->format('Y-m-d H:i:s'))
                ->setParameter('id', $id)
                ->executeStatement();
            if ($claimed !== 1) {
                continue;
            }

            $result = $this->executeConfiguredCronTask($request, $row);
            $intervalMinutes = max(1, (int) ($row['interval_minutes'] ?? 5));
            $nextRunAt = (new \DateTimeImmutable())->add(new \DateInterval(sprintf('PT%dM', $intervalMinutes)));

            $cronUpdate = [
                'running' => 0,
                'locked_at' => null,
                'last_run_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'next_run_at' => $nextRunAt->format('Y-m-d H:i:s'),
                'last_status' => $result['success'] ? 'OK' : 'ERROR',
                'last_message' => mb_substr($result['message'], 0, 1000),
            ];
            if (in_array('remaining_items', $cronColumns, true) && $result['remainingItems'] !== null) {
                $cronUpdate['remaining_items'] = $result['remainingItems'];
            }
            $connection->update($table, $cronUpdate, ['id' => $id]);

            $results[] = $result + [
                'id' => $id,
                'name' => (string) ($row['name'] ?? ''),
                'nextRunAt' => $nextRunAt->format('Y-m-d H:i:s'),
            ];
        }

        return new JsonResponse([
            'success' => true,
            'checkedAt' => $now->format('Y-m-d H:i:s'),
            'executed' => count($results),
            'results' => $results,
        ]);
    }

    public function processEmailQueue(Request $request): Response
    {
        if (!$this->isAutomationRequestAuthorized($request)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak autoryzacji do uruchomienia kolejki e-mail.',
            ], 403);
        }

        $limit = max(1, (int) $request->query->get('limit', 20));
        $delaySeconds = max(0, (int) $request->query->get('delaySeconds', 0));
        $result = $this->mailer->processQueue($limit, $delaySeconds);

        return new JsonResponse([
            'success' => true,
            'processed' => $result['processed'],
            'sent' => $result['sent'],
            'failed' => $result['failed'],
            'pending' => $result['pending'],
        ]);
    }

    public function processGraphicCodeImport(Request $request): Response
    {
        if (!$this->isAutomationRequestAuthorized($request)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak autoryzacji do importu kodów graficznych.',
            ], 403);
        }

        $limit = min(2000, max(1, (int) $request->query->get('limit', 250)));
        $minimumAgeSeconds = max(0, (int) $request->query->get('minAgeSeconds', 60));
        $inputDirectory = trim((string) $request->query->get('inputDirectory', ''));

        try {
            $result = (new GraphicCodeImporter(null, $inputDirectory !== '' ? $inputDirectory : null))->import(
                $this->entityManager->getConnection(),
                $limit,
                $minimumAgeSeconds
            );
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Import kodów graficznych nie powiódł się: ' . $exception->getMessage(),
                'remaining' => 0,
            ], 500);
        }

        return new JsonResponse($result, $result['success'] ? 200 : 500);
    }

    public function processWinnerPostCreateQueue(Request $request): Response
    {
        if (!$this->isAutomationRequestAuthorized($request)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak autoryzacji do przetwarzania zwycięzców po imporcie.',
            ], 403);
        }

        $connection = $this->entityManager->getConnection();
        $table = 'loteria_zwyciezcy_entries';
        $requiredColumns = [
            'post_create_processing_status',
            'post_create_send_email',
            'post_create_attempts',
            'post_create_last_error',
            'post_create_locked_at',
            'post_create_next_attempt_at',
            'post_create_processed_at',
        ];
        $schemaManager = $connection->createSchemaManager();
        if (!$schemaManager->tablesExist([$table])) {
            return new JsonResponse(['success' => false, 'message' => 'Tabela zwycięzców nie istnieje.'], 500);
        }
        $columns = array_keys($schemaManager->listTableColumns($table));
        if (array_diff($requiredColumns, $columns) !== []) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak pól kolejki przetwarzania zwycięzców. Uruchom instalację/aktualizację bazy danych w CMS.',
            ], 500);
        }

        // Runtime queue schema upgrades must happen before per-winner transactions (MySQL DDL commits implicitly).
        $this->mailer->ensureQueueSchemaReady();

        $limit = min(100, max(1, (int) $request->query->get('limit', 25)));
        $maxAttempts = min(20, max(1, (int) $request->query->get('maxAttempts', 5)));
        $retryDelaySeconds = max(5, (int) $request->query->get('retryDelaySeconds', 300));
        $now = new \DateTimeImmutable();
        $nowValue = $now->format('Y-m-d H:i:s');
        $staleLockValue = $now->sub(new \DateInterval('PT15M'))->format('Y-m-d H:i:s');
        $claimableCondition = "((post_create_processing_status = 'pending'"
            . ' AND (post_create_next_attempt_at IS NULL OR post_create_next_attempt_at <= :now))'
            . " OR (post_create_processing_status = 'processing'"
            . ' AND (post_create_locked_at IS NULL OR post_create_locked_at < :stale_lock)))';

        $exhaustedFailed = $connection->createQueryBuilder()
            ->update($table)
            ->set('post_create_processing_status', ':failed')
            ->set('post_create_last_error', "COALESCE(NULLIF(post_create_last_error, ''), :attempts_error)")
            ->set('post_create_locked_at', 'NULL')
            ->set('post_create_next_attempt_at', 'NULL')
            ->where($claimableCondition)
            ->andWhere('COALESCE(post_create_attempts, 0) >= :max_attempts')
            ->setParameter('failed', 'failed')
            ->setParameter('attempts_error', 'Przekroczono maksymalną liczbę prób przetwarzania.')
            ->setParameter('now', $nowValue)
            ->setParameter('stale_lock', $staleLockValue)
            ->setParameter('max_attempts', $maxAttempts)
            ->executeStatement();

        $candidateIds = $connection->createQueryBuilder()
            ->select('id')
            ->from($table)
            ->where($claimableCondition)
            ->andWhere('COALESCE(post_create_attempts, 0) < :max_attempts')
            ->setParameter('now', $nowValue)
            ->setParameter('stale_lock', $staleLockValue)
            ->setParameter('max_attempts', $maxAttempts)
            ->orderBy('id', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchFirstColumn();

        $processed = 0;
        $completed = 0;
        $retried = 0;
        $failed = $exhaustedFailed;
        foreach ($candidateIds as $candidateId) {
            $winnerId = (int) $candidateId;
            if ($winnerId <= 0) {
                continue;
            }

            $claimed = $connection->createQueryBuilder()
                ->update($table)
                ->set('post_create_processing_status', ':processing')
                ->set('post_create_locked_at', ':locked_at')
                ->set('post_create_attempts', 'COALESCE(post_create_attempts, 0) + 1')
                ->where('id = :id')
                ->andWhere($claimableCondition)
                ->setParameter('processing', 'processing')
                ->setParameter('locked_at', $nowValue)
                ->setParameter('id', $winnerId)
                ->setParameter('now', $nowValue)
                ->setParameter('stale_lock', $staleLockValue)
                ->executeStatement();
            if ($claimed !== 1) {
                continue;
            }

            ++$processed;
            $winner = false;
            $error = '';
            $connection->beginTransaction();
            try {
                $winner = $connection->createQueryBuilder()
                    ->select('*')
                    ->from($table)
                    ->where('id = :id')
                    ->setParameter('id', $winnerId)
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchAssociative();
                if (!is_array($winner) || $winner === []) {
                    throw new \RuntimeException('Nie znaleziono zwycięzcy.');
                }
                $statusId = trim((string) ($winner['stan'] ?? ''));
                if ($statusId !== '') {
                    $oldWinner = $winner;
                    $oldWinner['stan'] = '';
                    $winner['_send_status_email_after_creation'] = filter_var(
                        $winner['post_create_send_email'] ?? false,
                        FILTER_VALIDATE_BOOLEAN
                    );
                    $winner['_force_status_email_queue'] = true;
                    $notices = (new ZwyciezcyModuleOnChangeController($this->entityManager, $this->mailer))->handle(
                        'onWinnerStatusChange',
                        'zwyciezcy',
                        'stan',
                        '',
                        $statusId,
                        $winnerId,
                        $winner,
                        $oldWinner
                    );
                    $errors = array_values(array_filter(array_map('trim', $notices['error'] ?? [])));
                    if ($errors !== []) {
                        throw new \RuntimeException(implode(' ', $errors));
                    }
                }

                $connection->update($table, [
                    'post_create_processing_status' => 'completed',
                    'post_create_last_error' => null,
                    'post_create_locked_at' => null,
                    'post_create_next_attempt_at' => null,
                    'post_create_processed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ], ['id' => $winnerId]);
                $connection->commit();
                ++$completed;
                continue;
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                $this->entityManager->clear();
                $error = trim($exception->getMessage());
            }

            $attempts = is_array($winner) ? (int) ($winner['post_create_attempts'] ?? 1) : 1;
            $permanentlyFailed = $attempts >= $maxAttempts;
            $connection->update($table, [
                'post_create_processing_status' => $permanentlyFailed ? 'failed' : 'pending',
                'post_create_last_error' => mb_substr($error, 0, 2000),
                'post_create_locked_at' => null,
                'post_create_next_attempt_at' => $permanentlyFailed
                    ? null
                    : (new \DateTimeImmutable())->add(new \DateInterval(sprintf('PT%dS', $retryDelaySeconds)))->format('Y-m-d H:i:s'),
            ], ['id' => $winnerId]);
            if ($permanentlyFailed) {
                ++$failed;
            } else {
                ++$retried;
            }
        }

        $remaining = (int) $connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($table)
            ->where("post_create_processing_status IN ('pending', 'processing')")
            ->executeQuery()
            ->fetchOne();

        return new JsonResponse([
            'success' => true,
            'message' => sprintf(
                'Przetworzono: %d, zakończono: %d, do ponowienia: %d, błędy trwałe: %d, pozostało: %d.',
                $processed,
                $completed,
                $retried,
                $failed,
                $remaining
            ),
            'processed' => $processed,
            'completed' => $completed,
            'retried' => $retried,
            'failed' => $failed,
            'remaining' => $remaining,
        ]);
    }

    public function processDeferredWinnerCodes(Request $request): Response
    {
        if (!$this->isAutomationRequestAuthorized($request)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak autoryzacji do przydzielania kodów oczekującym zwycięzcom.',
            ], 403);
        }

        $connection = $this->entityManager->getConnection();
        $winnersTable = 'loteria_zwyciezcy_entries';
        $statusesTable = 'ustawienia_statusow';
        $prizeTypesTable = 'rodzaje_nagrod_entries';
        $codesTable = 'lista_wygranych_kodow_entries';
        $graphicCodesTable = GraphicCodeImporter::TABLE;
        $schemaManager = $connection->createSchemaManager();
        foreach ([$winnersTable, $statusesTable, $prizeTypesTable, $codesTable, $graphicCodesTable, 'email_queue'] as $requiredTable) {
            if (!$schemaManager->tablesExist([$requiredTable])) {
                return new JsonResponse([
                    'success' => false,
                    'message' => sprintf('Brak wymaganej tabeli „%s”.', $requiredTable),
                ], 500);
            }
        }

        $statusColumns = array_map('strtolower', array_keys($schemaManager->listTableColumns($statusesTable)));
        $winnerColumns = array_map('strtolower', array_keys($schemaManager->listTableColumns($winnersTable)));
        $prizeTypeColumns = array_map('strtolower', array_keys($schemaManager->listTableColumns($prizeTypesTable)));
        $codeColumns = array_map('strtolower', array_keys($schemaManager->listTableColumns($codesTable)));
        $graphicCodeColumns = array_map('strtolower', array_keys($schemaManager->listTableColumns($graphicCodesTable)));
        if (
            !in_array('do_wyslania_kodow', $statusColumns, true)
            || array_diff(['stan', 'rodzaj_wygranej'], $winnerColumns) !== []
            || !in_array('status_po_wygraniu_nagrody', $prizeTypeColumns, true)
            || !in_array('kod_przyznany_do_zwyciezcy', $codeColumns, true)
            || !in_array('kod_przyznany_do_zwyciezcy', $graphicCodeColumns, true)
        ) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak pól wymaganych przez CRON kodów oczekujących. Uruchom dedykowany patch bazy danych.',
            ], 500);
        }

        // Ewentualna aktualizacja schematu kolejki musi odbyć się przed transakcjami zwycięzców.
        $this->mailer->ensureQueueSchemaReady();

        $waitingStatusIds = array_values(array_filter(array_map(
            static fn(mixed $id): string => trim((string) $id),
            $connection->createQueryBuilder()
                ->select('id')
                ->from($statusesTable)
                ->where('do_wyslania_kodow = :enabled')
                ->setParameter('enabled', 1)
                ->orderBy('id', 'ASC')
                ->executeQuery()
                ->fetchFirstColumn()
        ), static fn(string $id): bool => $id !== ''));
        if ($waitingStatusIds === []) {
            return new JsonResponse([
                'success' => true,
                'message' => 'Brak statusu oznaczonego jako „Do wysłania kodów jak będą dostępne”.',
                'processed' => 0,
                'completed' => 0,
                'waiting' => 0,
                'failed' => 0,
                'remaining' => 0,
            ]);
        }

        $limit = min(200, max(1, (int) $request->query->get('limit', 50)));
        $scanLimit = min(1000, max($limit, (int) $request->query->get('scanLimit', $limit * 5)));
        $unassignedCondition = sprintf(
            'NOT EXISTS (SELECT 1 FROM %s deferred_code WHERE deferred_code.kod_przyznany_do_zwyciezcy = winner.id)'
            . ' AND NOT EXISTS (SELECT 1 FROM %s deferred_graphic_code WHERE deferred_graphic_code.kod_przyznany_do_zwyciezcy = winner.id)',
            $codesTable,
            $graphicCodesTable
        );

        $prizeTypeIds = $connection->createQueryBuilder()
            ->select('DISTINCT winner.rodzaj_wygranej')
            ->from($winnersTable, 'winner')
            ->innerJoin('winner', $statusesTable, 'waiting_status', 'waiting_status.id = winner.stan')
            ->where('waiting_status.do_wyslania_kodow = :enabled')
            ->andWhere('winner.rodzaj_wygranej IS NOT NULL')
            ->andWhere("winner.rodzaj_wygranej <> ''")
            ->andWhere($unassignedCondition)
            ->setParameter('enabled', 1)
            ->orderBy('winner.rodzaj_wygranej', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        $candidateIdsByPrizeType = [];
        foreach ($prizeTypeIds as $prizeTypeId) {
            $normalizedPrizeTypeId = trim((string) $prizeTypeId);
            if ($normalizedPrizeTypeId === '') {
                continue;
            }

            $candidateIdsByPrizeType[$normalizedPrizeTypeId] = array_values(array_map(
                'intval',
                $connection->createQueryBuilder()
                    ->select('winner.id')
                    ->from($winnersTable, 'winner')
                    ->innerJoin('winner', $statusesTable, 'waiting_status', 'waiting_status.id = winner.stan')
                    ->where('waiting_status.do_wyslania_kodow = :enabled')
                    ->andWhere('winner.rodzaj_wygranej = :prize_type_id')
                    ->andWhere($unassignedCondition)
                    ->setParameter('enabled', 1)
                    ->setParameter('prize_type_id', $normalizedPrizeTypeId)
                    ->orderBy('winner.id', 'ASC')
                    ->setMaxResults($scanLimit)
                    ->executeQuery()
                    ->fetchFirstColumn()
            ));
        }

        $processed = 0;
        $completed = 0;
        $waiting = 0;
        $failed = 0;
        $errors = [];
        $blockedPrizeTypes = [];
        while ($candidateIdsByPrizeType !== [] && $completed < $limit && $processed < $scanLimit) {
            foreach (array_keys($candidateIdsByPrizeType) as $prizeTypeId) {
                if ($completed >= $limit || $processed >= $scanLimit) {
                    break 2;
                }
                if (isset($blockedPrizeTypes[$prizeTypeId])) {
                    unset($candidateIdsByPrizeType[$prizeTypeId]);
                    continue;
                }

                $winnerId = (int) array_shift($candidateIdsByPrizeType[$prizeTypeId]);
                if ($candidateIdsByPrizeType[$prizeTypeId] === []) {
                    unset($candidateIdsByPrizeType[$prizeTypeId]);
                }
                if ($winnerId <= 0) {
                    continue;
                }

                ++$processed;
                $result = $this->processDeferredWinnerCodeCandidate(
                    $connection,
                    $winnerId,
                    $waitingStatusIds,
                    $winnersTable,
                    $codesTable,
                    $graphicCodesTable
                );
                $state = (string) ($result['state'] ?? 'failed');
                if ($state === 'completed') {
                    ++$completed;
                    continue;
                }
                if ($state === 'waiting') {
                    ++$waiting;
                    $blockedPrizeTypes[$prizeTypeId] = true;
                    unset($candidateIdsByPrizeType[$prizeTypeId]);
                    continue;
                }
                if ($state === 'skipped') {
                    continue;
                }

                ++$failed;
                $error = trim((string) ($result['error'] ?? 'Nieznany błąd.'));
                if (count($errors) < 5) {
                    $errors[] = sprintf('Zwycięzca #%d: %s', $winnerId, $error);
                }
                if ($state === 'configuration_error') {
                    $blockedPrizeTypes[$prizeTypeId] = true;
                    unset($candidateIdsByPrizeType[$prizeTypeId]);
                }
            }
        }

        $remaining = (int) $connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($winnersTable, 'winner')
            ->innerJoin('winner', $statusesTable, 'waiting_status', 'waiting_status.id = winner.stan')
            ->where('waiting_status.do_wyslania_kodow = :enabled')
            ->andWhere($unassignedCondition)
            ->setParameter('enabled', 1)
            ->executeQuery()
            ->fetchOne();

        $message = sprintf(
            'Sprawdzono: %d, przydzielono kody i zmieniono status: %d, nadal oczekuje na kody: %d, błędy: %d, pozostało: %d.',
            $processed,
            $completed,
            $waiting,
            $failed,
            $remaining
        );
        if ($errors !== []) {
            $message .= ' ' . implode(' ', $errors);
        }

        return new JsonResponse([
            'success' => $failed === 0,
            'message' => $message,
            'processed' => $processed,
            'completed' => $completed,
            'waiting' => $waiting,
            'failed' => $failed,
            'remaining' => $remaining,
        ]);
    }

    /**
     * @param array<int, string> $waitingStatusIds
     * @return array{state: string, error?: string}
     */
    private function processDeferredWinnerCodeCandidate(
        Connection $connection,
        int $winnerId,
        array $waitingStatusIds,
        string $winnersTable,
        string $codesTable,
        string $graphicCodesTable
    ): array {
        $connection->beginTransaction();
        try {
            $winnerQuery = $connection->createQueryBuilder()
                ->select('*')
                ->from($winnersTable)
                ->where('id = :id')
                ->setParameter('id', $winnerId)
                ->setMaxResults(1);
            $winnerSql = $winnerQuery->getSQL();
            if (in_array(strtolower($connection->getDatabasePlatform()->getName()), ['mysql', 'mariadb', 'postgresql'], true)) {
                $winnerSql .= ' FOR UPDATE';
            }
            $winner = $connection
                ->executeQuery($winnerSql, $winnerQuery->getParameters(), $winnerQuery->getParameterTypes())
                ->fetchAssociative();
            if (!is_array($winner) || $winner === []) {
                $connection->rollBack();

                return ['state' => 'skipped'];
            }

            $waitingStatus = trim((string) ($winner['stan'] ?? ''));
            if (!in_array($waitingStatus, $waitingStatusIds, true)) {
                $connection->commit();

                return ['state' => 'skipped'];
            }

            $assignedCodesCount = (int) $connection->createQueryBuilder()
                ->select('COUNT(*)')
                ->from($codesTable)
                ->where('kod_przyznany_do_zwyciezcy = :winner_id')
                ->setParameter('winner_id', $winnerId)
                ->executeQuery()
                ->fetchOne();
            $assignedCodesCount += (int) $connection->createQueryBuilder()
                ->select('COUNT(*)')
                ->from($graphicCodesTable)
                ->where('kod_przyznany_do_zwyciezcy = :winner_id')
                ->setParameter('winner_id', $winnerId)
                ->executeQuery()
                ->fetchOne();
            if ($assignedCodesCount > 0) {
                $connection->commit();

                return ['state' => 'skipped'];
            }

            $assignmentResult = (new WinnerPrizeCodeAssignmentService())
                ->assignConfiguredCodesToWinner($connection, $winner);
            if (!$assignmentResult['success']) {
                $failureReason = trim((string) ($assignmentResult['failure_reason'] ?? ''));
                $error = trim((string) ($assignmentResult['error'] ?? 'Nie udało się przydzielić kodów.'));
                $connection->rollBack();

                return $failureReason === 'insufficient_codes'
                    ? ['state' => 'waiting']
                    : ['state' => 'failed', 'error' => $error];
            }

            if (filter_var($assignmentResult['code_shortage'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                // Serwis przydzielania może ponownie ustawić status awaryjny; do czasu
                // pojawienia się pełnego kompletu kodów zwycięzca ma zachować bieżący status.
                $connection->rollBack();

                return ['state' => 'waiting'];
            }
            if (($assignmentResult['skipped'] ?? false) || !($assignmentResult['assigned'] ?? false)) {
                $connection->rollBack();

                return [
                    'state' => 'configuration_error',
                    'error' => 'Rodzaj nagrody nie ma skonfigurowanych kodów do przydzielenia.',
                ];
            }

            $winner = $assignmentResult['winner'];
            $prizeType = is_array($assignmentResult['prize_type'] ?? null) ? $assignmentResult['prize_type'] : [];
            $targetStatus = trim((string) ($prizeType['status_po_wygraniu_nagrody'] ?? ''));
            if ($targetStatus === '') {
                $connection->rollBack();

                return [
                    'state' => 'configuration_error',
                    'error' => 'Rodzaj nagrody nie ma ustawionego pola „Status po wygraniu nagrody”.',
                ];
            }
            if ($targetStatus === $waitingStatus) {
                $connection->rollBack();

                return [
                    'state' => 'configuration_error',
                    'error' => 'Status docelowy jest jednocześnie statusem oczekiwania na kody.',
                ];
            }

            $connection->update($winnersTable, ['stan' => $targetStatus], ['id' => $winnerId]);
            $winner['stan'] = $targetStatus;
            $emailService = new WinnerStatusEmailService($this->mailer);
            $administratorResult = $emailService->sendToAdministratorForStatus(
                $connection,
                $winner,
                $winnerId,
                $targetStatus,
                true
            );
            if (!$administratorResult['success']) {
                $error = trim((string) ($administratorResult['error'] ?? 'Nie udało się dodać maila administratora do kolejki.'));
                $connection->rollBack();
                $this->entityManager->clear();

                return ['state' => 'configuration_error', 'error' => $error];
            }

            $winnerEmailResult = $emailService->sendForStatus(
                $connection,
                $winner,
                $winnerId,
                $winnersTable,
                $targetStatus,
                false,
                false,
                true
            );
            if (!$winnerEmailResult['success']) {
                $error = trim((string) ($winnerEmailResult['error'] ?? 'Nie udało się dodać maila zwycięzcy do kolejki.'));
                $connection->rollBack();
                $this->entityManager->clear();

                return [
                    'state' => str_contains($error, 'Brak adresu e-mail') ? 'failed' : 'configuration_error',
                    'error' => $error,
                ];
            }

            $connection->commit();

            return ['state' => 'completed'];
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->entityManager->clear();

            return [
                'state' => 'failed',
                'error' => trim($exception->getMessage()) !== '' ? trim($exception->getMessage()) : 'Nieznany błąd.',
            ];
        }
    }

    public function sendErrorSummary(Request $request): Response
    {
        if (!$this->isAutomationRequestAuthorized($request)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak autoryzacji do wysyłki podsumowania błędów.',
            ], 403);
        }

        if (!$this->isErrorEmailNotificationEnabled()) {
            return new JsonResponse([
                'success' => true,
                'message' => 'Powiadomienia e-mail o błędach są wyłączone.',
                'sent' => false,
                'count' => 0,
            ]);
        }

        $adminEmail = trim((string) ($this->config['mail']['admin'] ?? ''));
        if ($adminEmail === '') {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak adresu e-mail administratora w konfiguracji.',
            ], 500);
        }

        $windowMinutes = max(1, (int) $request->query->get('windowMinutes', 15));
        $to = new \DateTimeImmutable();
        $from = $to->sub(new \DateInterval(sprintf('PT%dM', $windowMinutes)));

        $logsQueryBuilder = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(ErrorLog::class, 'e')
            ->where('e.createdAt >= :from')
            ->andWhere('e.createdAt < :to')
            ->orderBy('e.createdAt', 'DESC')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if ($this->isError404ExcludedFromSummary()) {
            $logsQueryBuilder
                ->andWhere('e.statusCode != :status404')
                ->setParameter('status404', 404);
        }

        $logs = $logsQueryBuilder
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        if ($logs === []) {
            return new JsonResponse([
                'success' => true,
                'message' => sprintf('Brak błędów z ostatnich %d minut.', $windowMinutes),
                'sent' => false,
                'count' => 0,
            ]);
        }

        $countsByStatus = [];
        $itemsHtml = [];

        foreach ($logs as $log) {
            if (!$log instanceof ErrorLog) {
                continue;
            }

            $statusCode = $log->getStatusCode();
            $countsByStatus[$statusCode] = ($countsByStatus[$statusCode] ?? 0) + 1;
            $itemsHtml[] = sprintf(
                '<li><strong>%s</strong> [%d] %s %s<br><small>IP: %s | %s</small></li>',
                htmlspecialchars($log->getCreatedAt()?->format('Y-m-d H:i:s') ?? '-', ENT_QUOTES, 'UTF-8'),
                $statusCode,
                htmlspecialchars((string) $log->getMethod(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $log->getUrl(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $log->getIpAddress(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $log->getExceptionMessage(), ENT_QUOTES, 'UTF-8')
            );
        }

        if ($itemsHtml === []) {
            return new JsonResponse([
                'success' => true,
                'message' => 'Brak poprawnych wpisów błędów do podsumowania.',
                'sent' => false,
                'count' => 0,
            ]);
        }

        ksort($countsByStatus);
        $statusSummary = [];
        foreach ($countsByStatus as $statusCode => $count) {
            $statusSummary[] = sprintf('%d: %d', (int) $statusCode, (int) $count);
        }

        $appName = (string) ($this->config['app']['name'] ?? 'CMS2');
        $subject = sprintf(
            '[%s] Podsumowanie błędów (%d min): %d',
            $appName,
            $windowMinutes,
            count($itemsHtml)
        );

        $body = sprintf(
            '<h2>Podsumowanie błędów z ostatnich %d minut</h2>
            <p><strong>Zakres:</strong> %s - %s</p>
            <p><strong>Liczba błędów:</strong> %d</p>
            <p><strong>Statusy:</strong> %s</p>
            <h3>Ostatnie wpisy</h3>
            <ol>%s</ol>',
            $windowMinutes,
            htmlspecialchars($from->format('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($to->format('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8'),
            count($itemsHtml),
            htmlspecialchars(implode(', ', $statusSummary), ENT_QUOTES, 'UTF-8'),
            implode('', $itemsHtml)
        );

        $result = $this->mailer->sendWithError($adminEmail, $subject, $body, false, null, true);
        if (!($result['success'] ?? false)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Nie udało się wysłać podsumowania błędów.',
                'error' => $result['error'] ?? null,
            ], 500);
        }

        return new JsonResponse([
            'success' => true,
            'sent' => true,
            'count' => count($itemsHtml),
            'windowMinutes' => $windowMinutes,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{commandKey: string, success: bool, httpStatus: int, message: string, remainingItems: int|null}
     */
    private function executeConfiguredCronTask(Request $baseRequest, array $row): array
    {
        $commandKey = trim((string) ($row['command_key'] ?? ''));
        $task = $this->getCronTaskDefinitions()[$commandKey] ?? null;
        if ($task === null) {
            return [
                'commandKey' => $commandKey,
                'success' => false,
                'httpStatus' => 0,
                'message' => 'Nieznana komenda CRON.',
                'remainingItems' => null,
            ];
        }

        parse_str(str_replace(["\r\n", "\r", "\n"], '&', trim((string) ($row['parameters'] ?? ''))), $parameters);
        $parameters = is_array($parameters) ? $parameters : [];
        if ($commandKey === 'email_queue') {
            $emailQueueLimit = (int) ($row['email_queue_limit'] ?? 0);
            if ($emailQueueLimit > 0) {
                $parameters['limit'] = $emailQueueLimit;
            }

            $parameters['delaySeconds'] = max(0, (int) ($row['email_queue_delay_seconds'] ?? 0));
        }

        $path = (string) $task['path'];
        $query = array_merge($baseRequest->query->all(), $parameters);
        unset($query['secret']);

        $taskRequest = Request::create($path . ($query !== [] ? '?' . http_build_query($query) : ''), 'GET', [], [], [], [
            'HTTP_HOST' => $baseRequest->getHost() ?: 'localhost',
            'REQUEST_SCHEME' => $baseRequest->getScheme(),
            'HTTPS' => $baseRequest->isSecure() ? 'on' : 'off',
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        try {
            $maxRuns = $commandKey === 'ai_receipt_check' ? max(1, (int) ($parameters['maxRuns'] ?? 1)) : 1;
            $success = true;
            $httpStatus = 200;
            $messages = [];
            $remainingItems = null;

            for ($run = 1; $run <= $maxRuns; $run++) {
                $response = $this->{$task['method']}($taskRequest);
                $httpStatus = $response->getStatusCode();
                $payload = json_decode($response->getContent(), true);
                $runSuccess = $httpStatus < 400 && (!is_array($payload) || (bool) ($payload['success'] ?? true));
                $messages[] = is_array($payload)
                    ? (string) ($payload['message'] ?? json_encode($payload, JSON_UNESCAPED_UNICODE))
                    : $response->getContent();
                if (is_array($payload) && array_key_exists('remaining', $payload)) {
                    $remainingItems = max(0, (int) $payload['remaining']);
                } elseif (is_array($payload) && array_key_exists('pending', $payload)) {
                    $remainingItems = max(0, (int) $payload['pending']);
                }
                $success = $success && $runSuccess;

                if (!$runSuccess || $commandKey !== 'ai_receipt_check' || (is_array($payload) && array_key_exists('processed', $payload) && !(bool) $payload['processed'])) {
                    break;
                }
            }

            return [
                'commandKey' => $commandKey,
                'success' => $success,
                'httpStatus' => $httpStatus,
                'message' => mb_substr(implode(' | ', array_filter($messages)), 0, 1000),
                'remainingItems' => $remainingItems,
            ];
        } catch (\Throwable $exception) {
            return [
                'commandKey' => $commandKey,
                'success' => false,
                'httpStatus' => 500,
                'message' => mb_substr($exception->getMessage(), 0, 1000),
                'remainingItems' => null,
            ];
        }
    }

    private function seedDefaultCronTasks(string $table): void
    {
        $connection = $this->entityManager->getConnection();
        $existing = (int) $connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($table)
            ->executeQuery()
            ->fetchOne();

        $existingCommandKeys = [];
        if ($existing > 0) {
            $rows = $connection->createQueryBuilder()
                ->select('command_key')
                ->from($table)
                ->executeQuery()
                ->fetchFirstColumn();
            $existingCommandKeys = array_fill_keys(array_map('strval', $rows), true);
        }

        foreach ($this->getCronTaskDefinitions() as $commandKey => $task) {
            if (isset($existingCommandKeys[$commandKey])) {
                continue;
            }
            $connection->insert($table, [
                'name' => $task['name'],
                'command_key' => $commandKey,
                'enabled' => (int) $task['enabled'],
                'interval_minutes' => $task['interval'],
                'parameters' => $task['parameters'],
                'email_queue_limit' => $task['email_queue_limit'] ?? null,
                'email_queue_delay_seconds' => $task['email_queue_delay_seconds'] ?? null,
                'next_run_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'last_status' => 'NEW',
                'running' => 0,
            ]);
        }
    }

    private function deletePathRecursively(string $path): bool
    {
        if (is_file($path) || is_link($path)) {
            return @unlink($path);
        }

        if (!is_dir($path)) {
            return false;
        }

        $items = scandir($path);
        if (is_array($items)) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $this->deletePathRecursively($path . '/' . $item);
            }
        }

        return @rmdir($path);
    }

    public function processFtpMediaOffload(Request $request): Response
    {
        if (!$this->isAutomationRequestAuthorized($request)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak autoryzacji do przenoszenia mediów na FTP.',
            ], 403);
        }

        $storage = new FtpMediaStorage((new SystemSettingsProvider($this->entityManager))->getFtpConfig());
        if (!$storage->isConfigured()) {
            return new JsonResponse([
                'success' => true,
                'message' => 'Przenoszenie mediów na FTP jest wyłączone albo konfiguracja FTP jest niepełna.',
                'processed' => 0,
                'moved' => 0,
                'failed' => 0,
            ]);
        }

        $limit = max(1, (int) $request->query->get('limit', 10));
        $rootDir = dirname(__DIR__, 2);
        $connection = $this->entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $processed = 0;
        $moved = 0;
        $failed = 0;

        foreach ((new ModuleDefinitionLoader())->getInstallDefinitions() as $definition) {
            if ($processed >= $limit) {
                break;
            }

            $moduleKey = trim((string) ($definition['key'] ?? ''));
            $table = trim((string) ($definition['database']['table'] ?? ''));
            $fields = $definition['fields'] ?? ($definition['module']['fields'] ?? []);
            if ($moduleKey === '' || $table === '' || !is_array($fields) || !$schemaManager->tablesExist([$table])) {
                continue;
            }

            $columns = $schemaManager->listTableColumns($table);
            foreach ($fields as $field) {
                if ($processed >= $limit) {
                    break 2;
                }
                if (!is_array($field) || !($field['external_ftp'] ?? false)) {
                    continue;
                }

                $fieldName = trim((string) ($field['name'] ?? ''));
                if ($fieldName === '' || !array_key_exists(strtolower($fieldName), $columns)) {
                    continue;
                }

                $rows = $connection->createQueryBuilder()
                    ->select($fieldName)
                    ->from($table)
                    ->where($fieldName . ' IS NOT NULL')
                    ->andWhere($fieldName . " != ''")
                    ->setMaxResults($limit - $processed)
                    ->executeQuery()
                    ->fetchFirstColumn();

                foreach ($rows as $storedPath) {
                    $filename = $this->extractModuleUploadFilename((string) $storedPath, $moduleKey);
                    if ($filename === null) {
                        continue;
                    }

                    $localPath = $rootDir . '/var/uploads/modules/' . $moduleKey . '/' . $filename;
                    if (!is_file($localPath)) {
                        continue;
                    }

                    $processed++;
                    if ($storage->uploadAndRemoveLocal($localPath, 'modules/' . $moduleKey . '/' . $filename)) {
                        $moved++;
                    } else {
                        $failed++;
                    }

                    if ($processed >= $limit) {
                        break 2;
                    }
                }
            }
        }

        return new JsonResponse([
            'success' => $failed === 0,
            'message' => sprintf('Przetworzono %d plików, przeniesiono %d, błędy: %d.', $processed, $moved, $failed),
            'processed' => $processed,
            'moved' => $moved,
            'failed' => $failed,
        ], $failed === 0 ? 200 : 500);
    }

    private function extractModuleUploadFilename(string $storedPath, string $moduleKey): ?string
    {
        $path = parse_url($storedPath, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $prefix = '/media/modules/' . rawurlencode($moduleKey) . '/';
        if (!str_starts_with($path, $prefix)) {
            return null;
        }

        $filename = basename(rawurldecode(substr($path, strlen($prefix))));
        if ($filename === '' || str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return null;
        }

        return $filename;
    }

    /**
     * @return array<string, array{name: string, path: string, method: string, enabled: bool, interval: int, parameters: string, email_queue_limit?: int, email_queue_delay_seconds?: int}>
     */
    private function getCronTaskDefinitions(): array
    {
        return [
            'email_queue' => [
                'name' => 'Kolejka e-maili',
                'path' => '/internal/cron/email-queue/process',
                'method' => 'processEmailQueue',
                'enabled' => true,
                'interval' => 2,
                'parameters' => '',
                'email_queue_limit' => 20,
                'email_queue_delay_seconds' => 0,
            ],
            'winner_post_create_queue' => [
                'name' => 'Przetwarzanie zwycięzców po imporcie',
                'path' => '/internal/cron/winners/post-create/process',
                'method' => 'processWinnerPostCreateQueue',
                'enabled' => true,
                'interval' => 1,
                'parameters' => 'limit=25&maxAttempts=5&retryDelaySeconds=300',
            ],
            'graphic_codes_import' => [
                'name' => 'Import kodów graficznych z katalogu',
                'path' => '/internal/cron/graphic-codes/import',
                'method' => 'processGraphicCodeImport',
                'enabled' => true,
                'interval' => 1,
                'parameters' => 'limit=250&minAgeSeconds=60',
            ],
            'winner_deferred_code_assignment' => [
                'name' => 'Przydzielanie dostępnych kodów oczekującym zwycięzcom',
                'path' => '/internal/cron/winners/deferred-codes/process',
                'method' => 'processDeferredWinnerCodes',
                'enabled' => true,
                'interval' => 1,
                'parameters' => 'limit=50&scanLimit=250',
            ],
            'scratchcards_reminder' => [
                'name' => 'Przypomnienie o zdrapkach',
                'path' => '/internal/cron/loteria/zdrapki/przypomnienie',
                'method' => 'remindUnrevealedScratchcards',
                'enabled' => true,
                'interval' => 5,
                'parameters' => '',
            ],
            'ai_receipt_check' => [
                'name' => 'AI - sprawdzanie paragonów zwycięzców',
                'path' => '/internal/cron/loteria/zwyciezcy/ai-sprawdz',
                'method' => 'verifyWinnerReceiptWithAi',
                'enabled' => true,
                'interval' => 5,
                'parameters' => 'maxRuns=10',
            ],
            'error_summary' => [
                'name' => 'Podsumowanie błędów',
                'path' => '/internal/cron/error-summary/send',
                'method' => 'sendErrorSummary',
                'enabled' => true,
                'interval' => 15,
                'parameters' => 'windowMinutes=15',
            ],
            'ftp_media_offload' => [
                'name' => 'Przenoszenie mediów na FTP',
                'path' => '/internal/cron/ftp-media/offload',
                'method' => 'processFtpMediaOffload',
                'enabled' => true,
                'interval' => 5,
                'parameters' => 'limit=10',
            ],
        ];
    }

    private function isErrorEmailNotificationEnabled(): bool
    {
        $settings = $this->fetchModuleEntry('system_settings') ?? [];

        if (!array_key_exists('error_email_notifications_enabled', $settings)) {
            return true;
        }

        return filter_var($settings['error_email_notifications_enabled'], FILTER_VALIDATE_BOOLEAN);
    }

    private function isError404ExcludedFromSummary(): bool
    {
        $settings = $this->fetchModuleEntry('system_settings') ?? [];

        if (!array_key_exists('error_email_skip_404', $settings)) {
            return false;
        }

        return filter_var($settings['error_email_skip_404'], FILTER_VALIDATE_BOOLEAN);
    }

    private function isAutomationRequestAuthorized(Request $request): bool
    {
        $ipAddress = trim((string) $request->getClientIp());
        if ($ipAddress === '127.0.0.1' || $ipAddress === '::1') {
            return true;
        }

        $settings = $this->fetchModuleEntry('system_settings') ?? [];
        $expectedSecret = trim((string) ($settings['automation_secret'] ?? ''));
        if ($expectedSecret === '') {
            return false;
        }

        $providedSecret = trim((string) $request->headers->get('X-Automation-Secret', ''));
        if ($providedSecret === '') {
            $providedSecret = trim((string) $request->query->get('secret', ''));
        }

        return $providedSecret !== '' && hash_equals($expectedSecret, $providedSecret);
    }

    public function verifyWinnerReceiptWithAi(Request $request): Response
    {
        $definition = $this->resolveModuleDefinition('zwyciezcy');
        $table = $definition['database']['table'] ?? null;

        if (!is_string($table) || $table === '') {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak konfiguracji modułu zwycięzców.',
            ], 500);
        }

        $connection = $this->entityManager->getConnection();
        $entry = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where('stan = :state')
            ->setParameter('state', 'do_sprawdzenia')
            ->orderBy('id', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($entry) || $entry === []) {
            return new JsonResponse([
                'success' => true,
                'message' => 'Brak wpisów do sprawdzenia.',
                'processed' => false,
            ]);
        }

        $winnerId = (int) ($entry['id'] ?? 0);
        if ($winnerId <= 0) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Nieprawidłowe ID wpisu.',
            ], 500);
        }

        $updated = $connection->update($table, [
            'stan' => 'sprawdzany_przez_ai',
        ], [
            'id' => $winnerId,
            'stan' => 'do_sprawdzenia',
        ]);

        if ($updated !== 1) {
            return new JsonResponse([
                'success' => true,
                'message' => 'Wpis został już pobrany do przetwarzania.',
                'processed' => false,
            ]);
        }

        $reporterEmail = trim((string) ($entry['email'] ?? ''));
        $forbiddenDomain = $this->resolveForbiddenDomainForEmail($reporterEmail);
        if ($forbiddenDomain !== null) {
            $connection->update($table, [
                'notatka_ai' => sprintf('Zgłoszenie pochodzi z zabronionej domeny %s', $forbiddenDomain),
                'odpowiedz_ai' => json_encode([
                    'blocked' => true,
                    'reason' => 'forbidden_domain',
                    'domain' => $forbiddenDomain,
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                'stan' => 'ai_sprawdzone_niepoprawnie',
            ], [
                'id' => $winnerId,
            ]);

            return new JsonResponse([
                'success' => true,
                'processed' => true,
                'winner_id' => $winnerId,
                'state' => 'ai_sprawdzone_niepoprawnie',
            ]);
        }

        $settings = $this->fetchModuleEntry('system_settings') ?? [];
        $lotterySettings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
        $apiKey = trim((string) ($settings['openai_api_key'] ?? ''));
        $model = trim((string) ($settings['openai_receipt_model'] ?? ''));
        if ($model === '') {
            $model = 'gpt-4.1-mini';
        }

        if ($apiKey === '') {
            $connection->update($table, [
                'odpowiedz_ai' => 'Brak klucza API OpenAI w ustawieniach systemu (pole: openai_api_key).',
                'stan' => 'do_sprawdzenia',
            ], ['id' => $winnerId]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Brak klucza API OpenAI.',
                'winner_id' => $winnerId,
            ], 500);
        }

        $receiptImagePayload = $this->loadAndResizeWinnerReceiptImage((string) ($entry['rachunek'] ?? ''));
        if ($receiptImagePayload === null) {
            $connection->update($table, [
                'odpowiedz_ai' => 'Nie udało się odczytać lub przetworzyć zdjęcia paragonu.',
                'stan' => 'do_sprawdzenia',
            ], ['id' => $winnerId]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Nie udało się odczytać zdjęcia paragonu.',
                'winner_id' => $winnerId,
            ], 500);
        }

        $isGiftCardPurchase = $this->isWinnerGiftCardPurchase($entry);
        $prompt = $isGiftCardPurchase
            ? $this->buildWinnerGiftCardPrompt($entry)
            : $this->buildWinnerReceiptPrompt($entry);
        $responseSchema = $isGiftCardPurchase
            ? $this->buildWinnerGiftCardResponseSchema()
            : $this->buildWinnerReceiptResponseSchema();
        $httpClient = HttpClient::create(['timeout' => 60]);

        try {
            $response = $httpClient->request('POST', 'https://api.openai.com/v1/responses', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'input' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => $prompt],
                            ['type' => 'input_image', 'image_url' => 'data:' . $receiptImagePayload['mime_type'] . ';base64,' . $receiptImagePayload['base64']],
                        ],
                    ]],
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'winner_receipt_verification',
                            'schema' => $responseSchema,
                            'strict' => true,
                        ],
                    ],
                ],
            ]);

            $payload = $response->toArray(false);
        } catch (\Throwable $exception) {
            $connection->update($table, [
                'odpowiedz_ai' => 'Błąd komunikacji z OpenAI: ' . $exception->getMessage(),
                'stan' => 'do_sprawdzenia',
            ], ['id' => $winnerId]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Błąd komunikacji z OpenAI.',
                'winner_id' => $winnerId,
            ], 502);
        }

        $aiText = $this->extractWinnerAiOutputText($payload);
        $decoded = json_decode($aiText, true);
        if (!is_array($decoded)) {
            $decoded = [
                'raw_output_text' => $aiText,
                'raw_response' => $payload,
            ];
        }

        $recognizedAmount = $isGiftCardPurchase
            ? ($decoded['recognized_topup_amount'] ?? null)
            : ($decoded['recognized_eligible_total'] ?? null);
        $recognizedAmountFloat = is_numeric($recognizedAmount) ? (float) $recognizedAmount : null;
        $aiNote = trim((string) ($decoded['notes'] ?? ''));
        if ($aiNote === '') {
            $aiNote = trim((string) ($decoded['raw_output_text'] ?? ''));
        }

        $isValid = $this->normalizeAiBoolean($decoded['is_valid'] ?? null);
        $matchesCenter = $this->normalizeAiBoolean($decoded['matches_center'] ?? null);
        $matchesReceiptNumber = $this->normalizeAiBoolean($decoded['matches_receipt_number'] ?? null);
        $matchesLotteryPeriod = $this->normalizeAiBoolean($decoded['matches_lottery_period'] ?? null);
        if ($isGiftCardPurchase) {
            $isGiftCardTopupConfirmation = $this->normalizeAiBoolean($decoded['is_gift_card_topup_confirmation'] ?? null);
            $matchesAmount = $this->normalizeAiBoolean($decoded['matches_amount'] ?? null);
            $winnerState = ($isValid && $isGiftCardTopupConfirmation && $matchesCenter && $matchesAmount && $matchesReceiptNumber && $matchesLotteryPeriod)
                ? 'ai_sprawdzone'
                : 'ai_sprawdzone_niepoprawnie';
        } else {
            $hasUncertainty = $this->normalizeAiBoolean($decoded['has_uncertainty'] ?? null);
            $matchesStore = $this->normalizeAiBoolean($decoded['matches_store'] ?? null);
            $declaredAmount = $decoded['declared_amount'] ?? null;
            $declaredAmountFloat = is_numeric($declaredAmount) ? (float) $declaredAmount : null;
            $minimumEligibleAmount = isset($lotterySettings['amount_per_ticket']) && is_numeric($lotterySettings['amount_per_ticket'])
                ? (float) $lotterySettings['amount_per_ticket']
                : 50.0;
            if ($minimumEligibleAmount <= 0) {
                $minimumEligibleAmount = 50.0;
            }
            $matchesDeclaredEligibleAmount = $declaredAmountFloat !== null
                ? ($recognizedAmountFloat !== null && abs($declaredAmountFloat - $recognizedAmountFloat) < 0.01)
                : ($recognizedAmountFloat !== null && $recognizedAmountFloat >= $minimumEligibleAmount);

            if (!$matchesDeclaredEligibleAmount) {
                $isValid = false;
            }

            $winnerState = ($isValid && !$hasUncertainty && $matchesCenter && $matchesStore && $matchesReceiptNumber && $matchesLotteryPeriod && $matchesDeclaredEligibleAmount)
                ? 'ai_sprawdzone'
                : 'ai_sprawdzone_niepoprawnie';
        }

        $autoSendCodesByAi = strtoupper(trim((string) ($lotterySettings['automatyczne_wysylanie_kodow_przez_ai'] ?? 'TAK')));
        if ($autoSendCodesByAi === 'NIE' && $winnerState === 'ai_sprawdzone') {
            $winnerState = 'ai_sprawdzone_niepoprawnie';
        }

        $usage = $payload['usage'] ?? [];
        $usedTokens = null;
        if (is_array($usage) && isset($usage['total_tokens']) && is_numeric($usage['total_tokens'])) {
            $usedTokens = (int) $usage['total_tokens'];
        }

        $connection->update($table, [
            'kwota_odczytana' => $recognizedAmountFloat,
            'notatka_ai' => $aiNote,
            'zuzyte_tokeny' => $usedTokens,
            'odpowiedz_ai' => json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'stan' => $winnerState,
        ], [
            'id' => $winnerId,
        ]);

        if ($winnerState === 'ai_sprawdzone') {
            try {
                $winnerWithStatus = $entry;
                $winnerWithStatus['id'] = $winnerId;
                $this->sendWinnerCodeEmail($winnerWithStatus, $request);
            } catch (\Throwable $exception) {
                // Błędy wysyłki e-maila nie mogą blokować procesu weryfikacji AI.
            }
        }

        return new JsonResponse([
            'success' => true,
            'processed' => true,
            'winner_id' => $winnerId,
            'state' => $winnerState,
            'eligible_amount' => $recognizedAmountFloat,
        ]);
    }

    private function extractWinnerAiOutputText(array $payload): string
    {
        $outputText = trim((string) ($payload['output_text'] ?? ''));
        if ($outputText !== '') {
            return $outputText;
        }

        $output = $payload['output'] ?? null;
        if (!is_array($output)) {
            return '';
        }

        foreach ($output as $message) {
            if (!is_array($message)) {
                continue;
            }

            $contentItems = $message['content'] ?? null;
            if (!is_array($contentItems)) {
                continue;
            }

            foreach ($contentItems as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }

                $text = trim((string) ($contentItem['text'] ?? ''));
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    private function normalizeAiBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value === 1 || $value === 1.0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['true', '1', 'yes', 'y', 'tak'], true)) {
                return true;
            }

            if (in_array($normalized, ['false', '0', 'no', 'n', 'nie', ''], true)) {
                return false;
            }
        }

        return false;
    }

    private function resolveSuggestedEmailDomain(string $email): ?string
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return null;
        }

        [, $emailDomain] = explode('@', $email, 2);
        $emailDomain = ltrim(trim($emailDomain), '@.');
        if ($emailDomain === '' || in_array($emailDomain, self::COMMON_EMAIL_DOMAINS, true)) {
            return null;
        }

        $bestSuggestion = null;
        $bestDistance = PHP_INT_MAX;
        foreach (self::COMMON_EMAIL_DOMAINS as $knownDomain) {
            if ($this->isLikelyEmailDomainTypo($emailDomain, $knownDomain)) {
                $distance = levenshtein($emailDomain, $knownDomain);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestSuggestion = $knownDomain;
                }
            }
        }

        return $bestSuggestion;
    }

    private function isSuggestedEmailDomainConfirmed(array $data, string $email): bool
    {
        $normalizedEmail = $this->normalizeEmailDomainWarningAckEmail($email);
        if ($normalizedEmail === '') {
            return false;
        }

        $confirmedEmail = $this->normalizeEmailDomainWarningAckEmail((string) ($data['email_domain_warning_ack'] ?? ''));
        if ($confirmedEmail !== '' && $confirmedEmail === $normalizedEmail) {
            $this->consumeRememberedSuggestedEmailDomainWarning($normalizedEmail);

            return true;
        }

        return $this->consumeRememberedSuggestedEmailDomainWarning($normalizedEmail);
    }

    private function rememberSuggestedEmailDomainWarning(string $email): void
    {
        $normalizedEmail = $this->normalizeEmailDomainWarningAckEmail($email);
        if ($normalizedEmail === '') {
            return;
        }

        $ackEmails = $this->getRememberedSuggestedEmailDomainWarnings();
        $ackEmails[$normalizedEmail] = time();
        if (count($ackEmails) > 20) {
            $ackEmails = array_slice($ackEmails, -20, null, true);
        }

        $_SESSION[self::EMAIL_DOMAIN_WARNING_ACK_SESSION_KEY] = $ackEmails;
    }

    /**
     * @return array<string, int>
     */
    private function getRememberedSuggestedEmailDomainWarnings(): array
    {
        $now = time();
        $ackEmails = $_SESSION[self::EMAIL_DOMAIN_WARNING_ACK_SESSION_KEY] ?? [];
        if (!is_array($ackEmails)) {
            $_SESSION[self::EMAIL_DOMAIN_WARNING_ACK_SESSION_KEY] = [];

            return [];
        }

        $normalizedAckEmails = [];
        foreach ($ackEmails as $email => $timestamp) {
            $normalizedEmail = $this->normalizeEmailDomainWarningAckEmail((string) $email);
            $timestamp = (int) $timestamp;
            if ($normalizedEmail === '' || $timestamp <= 0 || $timestamp + self::EMAIL_DOMAIN_WARNING_ACK_TTL_SECONDS < $now) {
                continue;
            }

            $normalizedAckEmails[$normalizedEmail] = $timestamp;
        }

        $_SESSION[self::EMAIL_DOMAIN_WARNING_ACK_SESSION_KEY] = $normalizedAckEmails;

        return $normalizedAckEmails;
    }

    private function consumeRememberedSuggestedEmailDomainWarning(string $email): bool
    {
        $normalizedEmail = $this->normalizeEmailDomainWarningAckEmail($email);
        if ($normalizedEmail === '') {
            return false;
        }

        $ackEmails = $this->getRememberedSuggestedEmailDomainWarnings();
        if (!array_key_exists($normalizedEmail, $ackEmails)) {
            return false;
        }

        unset($ackEmails[$normalizedEmail]);
        $_SESSION[self::EMAIL_DOMAIN_WARNING_ACK_SESSION_KEY] = $ackEmails;

        return true;
    }

    private function normalizeEmailDomainWarningAckEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function isLikelyEmailDomainTypo(string $emailDomain, string $knownDomain): bool
    {
        if (levenshtein($emailDomain, $knownDomain) <= 1) {
            return true;
        }

        [$emailSecondLevel, $emailTopLevel] = $this->splitEmailDomainForTypoCheck($emailDomain);
        [$knownSecondLevel, $knownTopLevel] = $this->splitEmailDomainForTypoCheck($knownDomain);

        if ($emailSecondLevel === '' || $knownSecondLevel === '') {
            return false;
        }

        if ($emailSecondLevel === $knownSecondLevel && $emailTopLevel !== $knownTopLevel) {
            return true;
        }

        if ($emailTopLevel !== $knownTopLevel) {
            return false;
        }

        return levenshtein($emailSecondLevel, $knownSecondLevel) <= 1
            || $this->hasSingleAdjacentTransposition($emailSecondLevel, $knownSecondLevel);
    }

    private function hasSingleAdjacentTransposition(string $value, string $expected): bool
    {
        if (strlen($value) !== strlen($expected) || $value === $expected) {
            return false;
        }

        $differences = [];
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] !== $expected[$i]) {
                $differences[] = $i;
            }
        }

        return count($differences) === 2
            && $differences[1] === $differences[0] + 1
            && $value[$differences[0]] === $expected[$differences[1]]
            && $value[$differences[1]] === $expected[$differences[0]];
    }

    private function splitEmailDomainForTypoCheck(string $domain): array
    {
        $parts = array_values(array_filter(explode('.', $domain), static fn (string $part): bool => $part !== ''));
        if (count($parts) < 2) {
            return [$domain, ''];
        }

        $topLevel = array_pop($parts);
        return [implode('.', $parts), $topLevel];
    }

    private function resolveForbiddenDomainForEmail(string $email): ?string
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return null;
        }

        [, $emailDomain] = explode('@', $email, 2);
        $emailDomain = ltrim(trim($emailDomain), '@.');
        if ($emailDomain === '') {
            return null;
        }

        $forbiddenDomains = $this->fetchModuleEntries('zabronione_domeny');
        foreach ($forbiddenDomains as $forbiddenDomainEntry) {
            $forbiddenDomain = mb_strtolower(trim((string) ($forbiddenDomainEntry['domena'] ?? '')));
            $forbiddenDomain = ltrim($forbiddenDomain, '@.');
            if ($forbiddenDomain === '') {
                continue;
            }

            if ($emailDomain === $forbiddenDomain || str_ends_with($emailDomain, '.' . $forbiddenDomain)) {
                return $forbiddenDomain;
            }
        }

        return null;
    }

    private function buildWinnerReceiptPrompt(array $entry): string
    {
        $center = $this->resolveWinnerRelationLabel('centra_handlowe', $entry['centrum_handlowe'] ?? null, 'name');
        $centerAddress = $this->resolveWinnerCenterAddress($entry['centrum_handlowe'] ?? null);
        $store = $this->resolveWinnerRelationLabel('lista_sklepow', $entry['sklep'] ?? null, 'nazwa_sklepu');
        $storeAltCompanyName = $this->resolveWinnerStoreAlternativeCompanyName($entry['sklep'] ?? null);
        $declaredAmount = trim((string) ($entry['kwota_z_rachunku'] ?? ''));
        $receiptNumber = trim((string) ($entry['numer_dowodu_zakupu'] ?? ''));
        $lotteryPeriod = $this->resolveWinnerLotteryPeriod($entry['loteria'] ?? null);
        $declaredAmountPromptLine = $declaredAmount !== '' ? "- zadeklarowana kwota brutto: {$declaredAmount}\n" : '';

        return <<<PROMPT
Zweryfikuj zgodność paragonu ze zgłoszeniem loteryjnym.

Dane ze zgłoszenia:
- centrum handlowe: {$center}
- adres centrum handlowego M1: {$centerAddress}
- sklep: {$store}
- alternatywna nazwa firmy ze sklepu: {$storeAltCompanyName}
- numer paragonu: {$receiptNumber}
{$declaredAmountPromptLine}- okres trwania loterii (włącznie): {$lotteryPeriod}

Zadanie:
1) Odczytaj dane z paragonu i sprawdź zgodność centrum/sklepu/numeru paragonu.
   Dla zgodności centrum handlowego traktuj adres z paragonu jako kluczowy:
   na górze paragonu mogą występować dwa adresy (np. adres sklepu i adres siedziby) —
   sprawdź oba i uznaj zgodność, jeśli którykolwiek z nich odpowiada adresowi centrum M1 ze zgłoszenia.
   jeśli adres sklepu/sprzedawcy na paragonie wskazuje ten sam adres co adres centrum M1 ze zgłoszenia,
   ustaw matches_center=true (nawet gdy nazwa sklepu/centrum jest zapisana wariantowo lub skrótowo).
   Gdy adres jest niezgodny albo brak pewności co do zgodności adresu, ustaw matches_center=false.
   W notatkach krótko podaj odczytany adres z paragonu.
   Jeśli na paragonie występuje alternatywna nazwa firmy ze zgłoszenia, uznaj, że paragon jest z właściwego sklepu.
2) Odczytaj datę sprzedaży z paragonu i sprawdź, czy mieści się w okresie trwania loterii.
3) Policz łączną kwotę brutto kwalifikowaną po odjęciu (jeśli występują):
   a) napojów alkoholowych,
   b) wyrobów tytoniowych i nikotynowych, e-papierosów, akcesoriów tytoniowych oraz produktów imitujących,
   c) produktów leczniczych,
   d) preparatów do początkowego żywienia niemowląt i przedmiotów służących do karmienia niemowląt.
4) Każdy produkt związany z alkoholem (np. piwo, wino, wódka, rum, whisky, drink, gin, tequila,
   brandy, koniak, likier, nalewka, amaretto, sake, rakija, vermut, wermut i podobne) traktuj jako wykluczony.
   Wyjątek: uwzględnij taki produkt w kwocie kwalifikowanej wyłącznie wtedy, gdy przy nim widnieje oznaczenie 0 lub 0.0.
5) Weryfikując numer paragonu pamiętaj: dla paragonu fiskalnego właściwy numer paragonu jest zawsze
   w prawej górnej części dokumentu. Nie używaj numerów drukowanych na dole paragonu jako numeru paragonu.
6) Jeśli przesłany dokument jest potwierdzeniem płatności kartą (np. slip terminala, wydruk z POS,
   potwierdzenie transakcji kartowej) i nie jest paragonem fiskalnym, ustaw is_valid=false.
   Jeżeli na dokumencie jest informacja/słowo "niefiskalny" (lub równoważne), zawsze traktuj dokument
   jako niefiskalny i ustaw is_valid=false.
   W takiej sytuacji ustaw także has_uncertainty=false i opisz w notes, że to nie jest paragon fiskalny.
7) Zasada bezpieczeństwa: jeśli masz jakąkolwiek niepewność co do odczytu DOWOLNEJ pozycji paragonu
   (np. nazwy produktu, ceny, ilości, stawki, klasyfikacji produktu, sumy, daty, numeru paragonu lub adresu),
   ustaw has_uncertainty=true i is_valid=false.
8) Jeśli wykryjesz produkty potencjalnie wykluczone (np. alkohol), ale nie możesz ich jednoznacznie
   odczytać/wycenić, ustaw has_uncertainty=true i is_valid=false (nie oznaczaj takiego zgłoszenia jako poprawne).
9) Oceń, czy zgłoszenie jest poprawne.
10) Jeśli matches_center=false lub matches_store=false, to zawsze ustaw is_valid=false.
11) Jeśli matches_receipt_number=false (numer paragonu się nie zgadza), to zawsze ustaw is_valid=false.
12) Jeśli zadeklarowana kwota brutto została podana, zgłoszenie nie jest pozytywne, jeśli recognized_eligible_total nie jest równe declared_amount.
    Jeśli zadeklarowana kwota nie została podana, ustaw declared_amount=null i oprzyj poprawność na odczytanej kwocie kwalifikowanej.

Wynik zwróć tylko jako JSON zgodny ze schematem.
PROMPT;
    }

    private function resolveWinnerStoreAlternativeCompanyName(mixed $rawStoreId): string
    {
        $storeId = trim((string) $rawStoreId);
        if ($storeId === '') {
            return '';
        }

        $definition = $this->resolveModuleDefinition('lista_sklepow');
        $table = $definition['database']['table'] ?? null;
        if (!is_string($table) || $table === '') {
            return '';
        }

        $value = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('company_alt_name')
            ->from($table)
            ->where('id = :id')
            ->setParameter('id', $storeId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if ($value === false || $value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function resolveWinnerCenterAddress(mixed $rawCenterId): string
    {
        $centerId = trim((string) $rawCenterId);
        if ($centerId === '') {
            return 'brak danych adresowych';
        }

        $centers = $this->fetchModuleEntries('centra_handlowe', ['id' => [$centerId]]);
        foreach ($centers as $center) {
            if ((string) ($center['id'] ?? '') !== $centerId) {
                continue;
            }

            $address = trim((string) ($center['adres'] ?? ''));
            if ($address !== '') {
                return $address;
            }

            break;
        }

        return 'brak danych adresowych';
    }

    private function buildWinnerGiftCardPrompt(array $entry): string
    {
        $center = $this->resolveWinnerRelationLabel('centra_handlowe', $entry['centrum_handlowe'] ?? null, 'name');
        $centerAddress = $this->resolveWinnerCenterAddress($entry['centrum_handlowe'] ?? null);
        $declaredAmount = trim((string) ($entry['kwota_z_rachunku'] ?? ''));
        $receiptNumber = trim((string) ($entry['numer_dowodu_zakupu'] ?? ''));
        $lotteryPeriod = $this->resolveWinnerLotteryPeriod($entry['loteria'] ?? null);

        return <<<PROMPT
Zweryfikuj potwierdzenie zasilenia Karty podarunkowej M1 ze zgłoszeniem loteryjnym.

Dane ze zgłoszenia:
- centrum handlowe: {$center}
- adres centrum handlowego M1: {$centerAddress}
- numer transakcji ze zgłoszenia: {$receiptNumber}
- zadeklarowana kwota zasilenia: {$declaredAmount}
- okres trwania loterii (włącznie): {$lotteryPeriod}

Zadanie:
1) Sprawdź, czy przesłany dokument jest potwierdzeniem zasilenia Karty podarunkowej M1.
2) Odczytaj adres z górnej części dokumentu (nagłówek, górne linie wydruku) i sprawdź, czy zgadza się z adresem centrum handlowego M1 ze zgłoszenia.
   Jeśli adres się nie zgadza, ustaw matches_center=false oraz is_valid=false i opisz to w notes.
3) Odczytaj kwotę zasilenia i sprawdź, czy zgadza się z kwotą podaną przez użytkownika.
4) Odczytaj numer transakcji z dokumentu i sprawdź, czy zgadza się z numerem dowodu zakupu ze zgłoszenia.
5) Odczytaj datę transakcji i sprawdź, czy mieści się w okresie trwania loterii.
6) Oceń, czy zgłoszenie jest poprawne.

Wynik zwróć tylko jako JSON zgodny ze schematem.
PROMPT;
    }

    private function buildWinnerReceiptResponseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['is_valid', 'has_uncertainty', 'matches_center', 'matches_store', 'matches_receipt_number', 'matches_lottery_period', 'recognized_sale_date', 'declared_amount', 'recognized_total', 'recognized_eligible_total', 'excluded_items_detected', 'notes'],
            'properties' => [
                'is_valid' => ['type' => 'boolean'],
                'has_uncertainty' => ['type' => 'boolean'],
                'matches_center' => ['type' => 'boolean'],
                'matches_store' => ['type' => 'boolean'],
                'matches_receipt_number' => ['type' => 'boolean'],
                'matches_lottery_period' => ['type' => 'boolean'],
                'recognized_sale_date' => ['type' => ['string', 'null']],
                'declared_amount' => ['type' => ['number', 'null']],
                'recognized_total' => ['type' => ['number', 'null']],
                'recognized_eligible_total' => ['type' => ['number', 'null']],
                'excluded_items_detected' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'notes' => ['type' => 'string'],
            ],
        ];
    }

    private function buildWinnerGiftCardResponseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['is_valid', 'is_gift_card_topup_confirmation', 'matches_center', 'matches_amount', 'matches_receipt_number', 'matches_lottery_period', 'recognized_sale_date', 'declared_amount', 'recognized_topup_amount', 'recognized_transaction_number', 'notes'],
            'properties' => [
                'is_valid' => ['type' => 'boolean'],
                'is_gift_card_topup_confirmation' => ['type' => 'boolean'],
                'matches_center' => ['type' => 'boolean'],
                'matches_amount' => ['type' => 'boolean'],
                'matches_receipt_number' => ['type' => 'boolean'],
                'matches_lottery_period' => ['type' => 'boolean'],
                'recognized_sale_date' => ['type' => ['string', 'null']],
                'declared_amount' => ['type' => ['number', 'null']],
                'recognized_topup_amount' => ['type' => ['number', 'null']],
                'recognized_transaction_number' => ['type' => ['string', 'null']],
                'notes' => ['type' => 'string'],
            ],
        ];
    }

    private function isWinnerGiftCardPurchase(array $entry): bool
    {
        return false;
    }

    private function resolveWinnerLotteryPeriod(mixed $lotteryId): string
    {
        $id = trim((string) $lotteryId);
        if ($id === '') {
            return 'brak danych o loterii';
        }

        $settings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
        $start = trim((string) ($settings['loteria_data_startu'] ?? ''));
        $end = trim((string) ($settings['loteria_data_zakonczenia'] ?? ''));

        if ($start === '' && $end === '') {
            return 'brak danych o loterii';
        }

        if ($start !== '' && $end !== '') {
            return sprintf('%s - %s', $start, $end);
        }

        if ($start !== '') {
            return sprintf('od %s', $start);
        }

        return sprintf('do %s', $end);
    }


    private function resolveWinnerRelationLabel(string $moduleKey, mixed $rawId, string $fallbackField = 'name'): string
    {
        $id = trim((string) $rawId);
        if ($id === '') {
            return '';
        }

        $definition = $this->resolveModuleDefinition($moduleKey);
        $table = $definition['database']['table'] ?? null;
        if (!is_string($table) || $table === '') {
            return $id;
        }

        $moduleConfig = $definition['module'] ?? [];
        $fields = $moduleConfig['fields'] ?? [];
        $labelField = $fallbackField;
        if (is_array($fields)) {
            foreach ($fields as $fieldConfig) {
                if (!is_array($fieldConfig)) {
                    continue;
                }
                if (($fieldConfig['name'] ?? '') === $fallbackField) {
                    $labelField = $fallbackField;
                    break;
                }
            }
        }

        $value = $this->entityManager->getConnection()->createQueryBuilder()
            ->select($labelField)
            ->from($table)
            ->where('id = :id')
            ->setParameter('id', $id)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if ($value === false || $value === null) {
            return $id;
        }

        return trim((string) $value);
    }

    /**
     * @return array{mime_type: string, base64: string}|null
     */
    private function loadAndResizeWinnerReceiptImage(string $relativePath): ?array
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '') {
            return null;
        }

        $absolutePath = $this->resolveStoredFileAbsolutePath($relativePath);
        if (!is_string($absolutePath) || $absolutePath === '' || !is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        $fileSize = @filesize($absolutePath);
        if (is_int($fileSize) && $fileSize > 20 * 1024 * 1024) {
            return null;
        }

        $imageMetadata = @getimagesize($absolutePath);
        $mimeType = is_array($imageMetadata) && isset($imageMetadata['mime']) && is_string($imageMetadata['mime'])
            ? $imageMetadata['mime']
            : null;
        $width = is_array($imageMetadata) && isset($imageMetadata[0]) ? (int) $imageMetadata[0] : 0;
        $height = is_array($imageMetadata) && isset($imageMetadata[1]) ? (int) $imageMetadata[1] : 0;
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        $maxShorterSide = 2000;
        $shorterSide = min($width, $height);
        $scale = $shorterSide > 0 ? min($maxShorterSide / $shorterSide, 1) : 1;
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $maxAllowedPixels = 30_000_000;
        if (($width * $height) > $maxAllowedPixels) {
            return $this->buildWinnerReceiptOriginalImagePayload($absolutePath, $mimeType, $fileSize, 20 * 1024 * 1024);
        }

        $estimatedBytesRequired = ((int) ($width * $height * 8)) + ((int) ($targetWidth * $targetHeight * 4)) + (5 * 1024 * 1024);
        $availableMemoryBytes = $this->getAvailablePhpMemoryBytes();
        if ($availableMemoryBytes !== null && $estimatedBytesRequired > $availableMemoryBytes) {
            return $this->buildWinnerReceiptOriginalImagePayload($absolutePath, $mimeType, $fileSize, 20 * 1024 * 1024);
        }

        $sourceImage = null;
        if ($mimeType === 'image/jpeg') {
            $sourceImage = @imagecreatefromjpeg($absolutePath);
        } elseif ($mimeType === 'image/png') {
            $sourceImage = @imagecreatefrompng($absolutePath);
        } elseif ($mimeType === 'image/gif') {
            $sourceImage = @imagecreatefromgif($absolutePath);
        } elseif ($mimeType === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $sourceImage = @imagecreatefromwebp($absolutePath);
        } elseif ($mimeType === 'image/bmp' && function_exists('imagecreatefrombmp')) {
            $sourceImage = @imagecreatefrombmp($absolutePath);
        }

        if ($sourceImage === false || $sourceImage === null) {
            return $this->buildWinnerReceiptOriginalImagePayload($absolutePath, $mimeType, $fileSize, 20 * 1024 * 1024);
        }

        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($targetImage === false) {
            imagedestroy($sourceImage);
            return $this->buildWinnerReceiptOriginalImagePayload($absolutePath, $mimeType, $fileSize, 20 * 1024 * 1024);
        }

        imagefill($targetImage, 0, 0, imagecolorallocate($targetImage, 255, 255, 255));
        imagecopyresampled($targetImage, $sourceImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($targetImage, null, 100);
        $jpegData = ob_get_clean();

        imagedestroy($sourceImage);
        imagedestroy($targetImage);

        if (!is_string($jpegData) || $jpegData === '') {
            return $this->buildWinnerReceiptOriginalImagePayload($absolutePath, $mimeType, $fileSize, 20 * 1024 * 1024);
        }

        return [
            'mime_type' => 'image/jpeg',
            'base64' => base64_encode($jpegData),
        ];
    }

    /**
     * @return array{mime_type: string, base64: string}|null
     */
    private function buildWinnerReceiptOriginalImagePayload(string $absolutePath, ?string $mimeType, int|false $fileSize, int $maxBytes): ?array
    {
        if (!is_int($fileSize) || $fileSize <= 0 || $fileSize > $maxBytes) {
            return null;
        }

        $rawFileData = @file_get_contents($absolutePath);
        if (!is_string($rawFileData) || $rawFileData === '') {
            return null;
        }

        if (!is_string($mimeType) || !str_starts_with($mimeType, 'image/')) {
            $mimeType = @mime_content_type($absolutePath);
            if (!is_string($mimeType) || !str_starts_with($mimeType, 'image/')) {
                $mimeType = 'image/jpeg';
            }
        }

        return [
            'mime_type' => $mimeType,
            'base64' => base64_encode($rawFileData),
        ];
    }

    private function getAvailablePhpMemoryBytes(): ?int
    {
        $memoryLimit = $this->parsePhpMemoryLimitBytes((string) ini_get('memory_limit'));
        if ($memoryLimit === null) {
            return null;
        }

        $currentUsage = memory_get_usage(true);
        $available = $memoryLimit - $currentUsage;

        return $available > 0 ? $available : 0;
    }

    private function parsePhpMemoryLimitBytes(string $memoryLimit): ?int
    {
        $value = trim($memoryLimit);
        if ($value === '' || $value === '-1') {
            return null;
        }

        if (!preg_match('/^(\d+)([KMG])?$/i', $value, $matches)) {
            return null;
        }

        $bytes = (int) $matches[1];
        $unit = strtoupper((string) ($matches[2] ?? ''));
        if ($unit === 'K') {
            $bytes *= 1024;
        } elseif ($unit === 'M') {
            $bytes *= 1024 * 1024;
        } elseif ($unit === 'G') {
            $bytes *= 1024 * 1024 * 1024;
        }

        return $bytes;
    }

    public function checkLosowaneZdrapkiTicket(Request $request): Response
    {
        if (($ageGateResponse = $this->requireAgeVerification($request)) !== null) {
            return $ageGateResponse;
        }
        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload) || !is_string($payload['scratch_token'] ?? null)
            || !is_string($payload['ticket_id'] ?? null) || !is_numeric($payload['submission_id'] ?? null)
        ) {
            return new JsonResponse(['success' => false, 'message' => 'Brak danych dostępu do e-zdrapki.'], 400);
        }
        $token = $payload['scratch_token'];
        $submissionId = (int) $payload['submission_id'];
        $ticketId = trim($payload['ticket_id']);
        $connection = $this->entityManager->getConnection();
        $submissionTable = $this->resolveModuleDefinition('loteria_zgloszenia')['database']['table'] ?? '';
        $scratchTable = $this->resolveModuleDefinition('loteria_zdrapki')['database']['table'] ?? '';
        if (!$submissionTable || !$scratchTable) {
            return new JsonResponse(['success' => false, 'message' => 'Brak konfiguracji modułów loterii.'], 500);
        }
        $access = new LotteryScratchcardAccess($connection, $submissionTable, $scratchTable);
        if (!$access->ownsSubmission($token, $submissionId)) {
            return new JsonResponse(['success' => false, 'message' => 'Ten link nie daje dostępu do wskazanej e-zdrapki.'], 403);
        }
        try {
            $response = $access->withActivationLock($submissionId, $ticketId, function () use ($request): Response {
                return $this->resolveLosowaneZdrapkiTicket($request);
            });
            if ($response instanceof JsonResponse && $response->getStatusCode() === 200) {
                $result = json_decode((string) $response->getContent(), true);
                if (is_array($result) && ($result['success'] ?? false)) {
                    $result['ticket_id'] = $ticketId;
                    $result['submission_id'] = $submissionId;
                    $result['ticket_entries'] = $access->pendingEntries($token);
                    $response->setData($result);
                }
            }
            $response->headers->set('Cache-Control', 'no-store');
            return $response;
        } catch (\Throwable $exception) {
            return new JsonResponse(['success' => false, 'message' => 'Nie udało się otworzyć e-zdrapki. Spróbuj ponownie.'], 503);
        }
    }

    private function resolveLosowaneZdrapkiTicket(Request $request): Response
    {
        if (($ageGateResponse = $this->requireAgeVerification($request)) !== null) {
            return $ageGateResponse;
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Nieprawidłowe dane żądania.',
            ], 400);
        }

        $ticketId = trim((string) ($payload['ticket_id'] ?? ''));
        $submissionId = (int) ($payload['submission_id'] ?? 0);

        if ($ticketId === '' || $submissionId <= 0) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak wymaganych danych losu.',
            ], 400);
        }

        $ticketDefinition = $this->resolveModuleDefinition('loteria_zdrapki');
        $scheduleDefinition = $this->resolveModuleDefinition('godziny_wygranych');
        $submissionDefinition = $this->resolveModuleDefinition('loteria_zgloszenia');
        $winnersDefinition = $this->resolveModuleDefinition('zwyciezcy');
        $prizeTypeDefinition = $this->resolveModuleDefinition('rodzaje_nagrod');

        $ticketTable = $ticketDefinition['database']['table'] ?? null;
        $scheduleTable = $scheduleDefinition['database']['table'] ?? null;
        $submissionTable = $submissionDefinition['database']['table'] ?? null;
        $winnersTable = $winnersDefinition['database']['table'] ?? null;
        $prizeTypeTable = $prizeTypeDefinition['database']['table'] ?? null;

        if (!$ticketTable || !$scheduleTable || !$submissionTable || !$winnersTable || !$prizeTypeTable) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak konfiguracji modułów loterii.',
            ], 500);
        }

        $connection = $this->entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $ticketColumns = array_keys($schemaManager->listTableColumns($ticketTable));
        $scheduleColumns = array_keys($schemaManager->listTableColumns($scheduleTable));
        $winnersColumns = array_keys($schemaManager->listTableColumns($winnersTable));
        $hasTicketColumn = static fn (string $column): bool => in_array($column, $ticketColumns, true);
        $hasScheduleColumn = static fn (string $column): bool => in_array($column, $scheduleColumns, true);
        $hasWinnerColumn = static fn (string $column): bool => in_array($column, $winnersColumns, true);

        $ticket = $connection->createQueryBuilder()
            ->select('*')
            ->from($ticketTable)
            ->where('ticket_id = :ticketId')
            ->andWhere('zgloszenie = :submissionId')
            ->setParameter('ticketId', $ticketId)
            ->setParameter('submissionId', $submissionId)
            ->executeQuery()
            ->fetchAssociative();

        if (!$ticket) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Nie znaleziono wskazanego losu.',
            ], 404);
        }

        if (!empty($ticket['win_checked_at'])) {
            $alreadyWon = !empty($ticket['wygrany']);
            $alreadyCheckedPrizeTypeId = $alreadyWon
                ? $this->resolveScratchcardPrizeTypeIdForWinnerTicket(
                    $connection,
                    (string) $winnersTable,
                    $ticket,
                    $hasWinnerColumn
                )
                : 0;
            if ($alreadyWon && $alreadyCheckedPrizeTypeId <= 0) {
                $alreadyCheckedPrizeTypeId = $this->resolveScratchcardPrizeTypeIdForTicket(
                    $connection,
                    (string) $scheduleTable,
                    $ticket,
                    $hasTicketColumn,
                    $hasScheduleColumn
                );
            }
            $alreadyCheckedPrizeName = $hasTicketColumn('prize_name') ? trim((string) ($ticket['prize_name'] ?? '')) : '';
            $alreadyCheckedPrizeType = $alreadyWon && $alreadyCheckedPrizeTypeId > 0
                ? $this->fetchScratchcardPrizeType($connection, (string) $prizeTypeTable, $alreadyCheckedPrizeTypeId)
                : [];
            if (
                $alreadyWon
                && trim((string) ($alreadyCheckedPrizeType['scratchcard_image_url'] ?? '')) === ''
            ) {
                $fallbackPrizeType = $this->fetchScratchcardPrizeTypeByName(
                    $connection,
                    (string) $prizeTypeTable,
                    $alreadyCheckedPrizeName,
                    trim((string) ($ticket['loteria'] ?? ''))
                );
                if ($fallbackPrizeType !== []) {
                    $alreadyCheckedPrizeType = $fallbackPrizeType;
                    $alreadyCheckedPrizeTypeId = (int) ($fallbackPrizeType['id'] ?? $alreadyCheckedPrizeTypeId);
                }
            }
            if ($alreadyCheckedPrizeName === '' && isset($alreadyCheckedPrizeType['display_name'])) {
                $alreadyCheckedPrizeName = (string) $alreadyCheckedPrizeType['display_name'];
            }

            return new JsonResponse([
                'success' => true,
                'already_checked' => true,
                'is_winner' => $alreadyWon,
                'prize_name' => $alreadyWon && $alreadyCheckedPrizeName !== '' ? $alreadyCheckedPrizeName : null,
                'voucher_code' => $hasTicketColumn('voucher_code') ? ($ticket['voucher_code'] ?? null) : null,
                'scratchcard_image_url' => $alreadyWon ? ($alreadyCheckedPrizeType['scratchcard_image_url'] ?? null) : null,
                'message' => $alreadyWon ? '🎉 Ten los już wcześniej został oznaczony jako wygrany.' : 'Ten los został już wcześniej sprawdzony i nie wygrał.',
            ]);
        }

        $lotteryId = trim((string) ($ticket['loteria'] ?? ''));

        try {
            $clickedAt = new \DateTimeImmutable();
            $platformName = strtolower($connection->getDatabasePlatform()->getName());
            $isMySqlPlatform = $platformName === 'mysql' || $platformName === 'mariadb';
            if ($platformName !== 'sqlite' && !$connection->isTransactionActive()) {
                $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            }

            $connection->beginTransaction();

            $lotterySettings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
            $scratchcardPrizeDrawType = trim((string) ($lotterySettings['scratchcard_prize_draw_type'] ?? 'nearest_past_hour_wins'));
            $winnerSelector = match ($scratchcardPrizeDrawType) {
                'every_scratchcard_wins' => new EveryScratchcardWinsSelector(),
                'every_scratchcard_wins_with_priority' => new EveryScratchcardWinsWithPrioritySelector(),
                'nearest_past_hour_wins', '' => new NearestPastHourScratchcardWinnerSelector(),
                default => new NearestPastHourScratchcardWinnerSelector(),
            };
            $winnerSelection = $winnerSelector->selectAndGrant(
                $connection,
                (string) $scheduleTable,
                $ticket,
                $isMySqlPlatform
            );

            $clickedAt = $winnerSelection['clicked_at'];
            $bestSchedule = $winnerSelection['schedule'];
            $bestScheduleWinAt = $winnerSelection['schedule_win_at'];
            $bestDiffMicros = $winnerSelection['diff_microseconds'];

            $isWinner = $winnerSelection['is_winner'];
            $prizeName = null;
            $voucherCode = null;
            $scratchcardImageUrl = null;
            $prizeTypeId = 0;
            $prizeType = [];
            $assignedScheduleId = null;

            if ($bestSchedule !== null) {
                if ($bestScheduleWinAt === null) {
                    $bestWinAtRaw = trim((string) ($bestSchedule['win_at'] ?? ''));
                    if ($bestWinAtRaw !== '') {
                        try {
                            $bestScheduleWinAt = new \DateTimeImmutable($bestWinAtRaw);
                        } catch (\Throwable $exception) {
                            $bestScheduleWinAt = null;
                        }
                    }
                }

                $assignedScheduleId = (int) ($bestSchedule['id'] ?? 0);
                $prizeName = $hasScheduleColumn('prize_name') ? trim((string) ($bestSchedule['prize_name'] ?? '')) : '';
                $voucherCode = $hasScheduleColumn('voucher_code') ? trim((string) ($bestSchedule['voucher_code'] ?? '')) : '';
                $rawPrizeTypeValue = $hasScheduleColumn('rodzaj_nagrody') ? trim((string) ($bestSchedule['rodzaj_nagrody'] ?? '')) : '';
                $prizeTypeId = $rawPrizeTypeValue !== '' ? (int) $rawPrizeTypeValue : 0;
                if ($prizeTypeId > 0) {
                    $prizeType = $this->fetchScratchcardPrizeType($connection, (string) $prizeTypeTable, $prizeTypeId);
                    if ($prizeType !== []) {
                        $relationPrizeName = trim((string) ($prizeType['display_name'] ?? ''));
                        if ($relationPrizeName !== '') {
                            $prizeName = $relationPrizeName;
                        }
                        $scratchcardImageUrl = $prizeType['scratchcard_image_url'] ?? null;
                    }
                }
                if ($prizeTypeId <= 0 && $rawPrizeTypeValue !== '') {
                    $prizeType = $this->fetchScratchcardPrizeTypeByName($connection, (string) $prizeTypeTable, $rawPrizeTypeValue, $lotteryId);
                    if ($prizeType !== []) {
                        $prizeTypeId = (int) ($prizeType['id'] ?? 0);
                        $relationPrizeName = trim((string) ($prizeType['display_name'] ?? ''));
                        if ($relationPrizeName !== '') {
                            $prizeName = $relationPrizeName;
                        }
                        $scratchcardImageUrl = $prizeType['scratchcard_image_url'] ?? null;
                    }
                }
            }

            if ($isWinner && trim((string) $scratchcardImageUrl) === '') {
                $fallbackPrizeType = $this->fetchScratchcardPrizeTypeByName(
                    $connection,
                    (string) $prizeTypeTable,
                    (string) $prizeName,
                    $lotteryId
                );
                if ($fallbackPrizeType !== []) {
                    $fallbackPrizeTypeId = (int) ($fallbackPrizeType['id'] ?? 0);
                    if ($fallbackPrizeTypeId > 0) {
                        $prizeTypeId = $fallbackPrizeTypeId;
                    }
                    $fallbackPrizeName = trim((string) ($fallbackPrizeType['display_name'] ?? ''));
                    if ($fallbackPrizeName !== '') {
                        $prizeName = $fallbackPrizeName;
                    }
                    $scratchcardImageUrl = $fallbackPrizeType['scratchcard_image_url'] ?? null;
                    $prizeType = $fallbackPrizeType;
                }
            }

            $prizeName = $isWinner ? ($prizeName !== '' ? $prizeName : null) : null;
            $voucherCode = $isWinner ? ($voucherCode !== '' ? $voucherCode : null) : null;
            $checkedAt = new \DateTimeImmutable();

            $ticketUpdateData = [];
            if ($hasTicketColumn('clicked_at')) {
                $ticketUpdateData['clicked_at'] = $clickedAt->format('Y-m-d H:i:s');
            }
            if ($hasTicketColumn('clicked_at_microseconds')) {
                $ticketUpdateData['clicked_at_microseconds'] = $clickedAt->format('Y-m-d H:i:s.u');
            }
            if ($hasTicketColumn('win_checked_at')) {
                $ticketUpdateData['win_checked_at'] = $checkedAt->format('Y-m-d H:i:s');
            }
            if ($hasTicketColumn('win_diff_microseconds')) {
                $ticketUpdateData['win_diff_microseconds'] = $isWinner ? $bestDiffMicros : null;
            }
            if ($hasTicketColumn('win_schedule_id')) {
                $ticketUpdateData['win_schedule_id'] = $isWinner ? $assignedScheduleId : null;
            }
            if ($hasTicketColumn('prize_name')) {
                $ticketUpdateData['prize_name'] = $prizeName;
            }
            if ($hasTicketColumn('voucher_code')) {
                $ticketUpdateData['voucher_code'] = $voucherCode;
            }
            if ($hasTicketColumn('wygrany')) {
                $ticketUpdateData['wygrany'] = $isWinner ? 1 : 0;
            }
            if ($hasTicketColumn('status')) {
                $ticketUpdateData['status'] = 'Zdrapana';
            }

            if ($ticketUpdateData !== []) {
                $connection->update($ticketTable, $ticketUpdateData, [
                    'id' => (int) $ticket['id'],
                ]);
            }

            $winnerRecordId = null;
            $winnerToken = null;
            if ($isWinner) {
                $submission = $connection->createQueryBuilder()
                    ->select('*')
                    ->from($submissionTable)
                    ->where('id = :id')
                    ->setParameter('id', (int) ($ticket['zgloszenie'] ?? 0))
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchAssociative() ?: [];

                $winnerToken = $this->generateScratchcardsToken();
                $winnerStatus = trim((string) ($prizeType['status_po_wygraniu_nagrody'] ?? ''));
                if ($winnerStatus === '') {
                    $winnerStatus = 'wyslane';
                }
                $winnerData = [
                    'imie' => trim((string) ($submission['imie'] ?? '')),
                    'nazwisko' => trim((string) ($submission['nazwisko'] ?? '')),
                    'email' => trim((string) (($submission['email'] ?? '') !== '' ? $submission['email'] : ($ticket['email'] ?? ''))),
                    'telefon' => trim((string) (($submission['phone'] ?? '') !== '' ? $submission['phone'] : ($ticket['phone'] ?? ''))),
                    'token_formularza' => $winnerToken,
                    'email_o_wygranej_wyslany_at' => null,
                    'email_o_poprawnej_weryfikacji_wyslany_at' => null,
                    'rodzaj' => 'zdrapka',
                    'zgloszenie' => (int) ($ticket['zgloszenie'] ?? 0) > 0 ? (int) $ticket['zgloszenie'] : null,
                    'kupon_w_loterii' => null,
                    'zdrapka_w_loterii' => (int) $ticket['id'],
                    'rodzaj_wygranej' => $prizeTypeId > 0 ? $prizeTypeId : null,
                    'loteria' => ($submission['loteria'] ?? null) ?: ($ticket['loteria'] ?? null),
                    'centrum_handlowe' => ($submission['centrum_handlowe'] ?? null) ?: ($ticket['centrum_handlowe'] ?? null),
                    'numer_dowodu_zakupu' => trim((string) ($submission['purchase_document_number'] ?? '')),
                    'kwota_z_rachunku' => ($submission['purchase_amount'] ?? null) !== '' ? ($submission['purchase_amount'] ?? null) : null,
                    'stan' => $winnerStatus,
                ];

                $winnerData = array_filter(
                    $winnerData,
                    static fn (string $column): bool => $hasWinnerColumn($column),
                    ARRAY_FILTER_USE_KEY
                );

                if ($hasWinnerColumn('zdrapka_w_loterii')) {
                    $existingWinner = $connection->createQueryBuilder()
                        ->select('id')
                        ->from($winnersTable)
                        ->where('zdrapka_w_loterii = :ticketId')
                        ->setParameter('ticketId', (int) $ticket['id'])
                        ->setMaxResults(1)
                        ->executeQuery()
                        ->fetchAssociative();
                } else {
                    $existingWinner = false;
                }

                if ($winnerData !== []) {
                    if ($existingWinner) {
                        $winnerRecordId = (int) ($existingWinner['id'] ?? 0);
                        $connection->update($winnersTable, $winnerData, ['id' => $winnerRecordId]);
                    } else {
                        $connection->insert($winnersTable, $winnerData);
                        $winnerRecordId = (int) $connection->lastInsertId();
                    }
                }
            }

            $connection->commit();

            if ($isWinner && $winnerToken !== null) {
                $winnerLink = $this->buildWinnerLink($request, $winnerToken);
                try {
                    $mailResult = $this->sendLotteryWinnerEmail($ticket, $prizeName, $voucherCode, $checkedAt, $winnerLink, $prizeTypeId);
                    if ($mailResult['success'] && $winnerRecordId !== null && $winnerRecordId > 0) {
                        $sentAt = new \DateTimeImmutable();
                        if ($hasWinnerColumn('email_o_wygranej_wyslany_at')) {
                            $connection->update($winnersTable, [
                                'email_o_wygranej_wyslany_at' => $sentAt->format('Y-m-d H:i:s'),
                            ], [
                                'id' => $winnerRecordId,
                            ]);
                        }

                        if ($hasWinnerColumn('historia_wysylek_email_status')) {
                            $winnerForHistory = $connection->createQueryBuilder()
                                ->select('*')
                                ->from($winnersTable)
                                ->where('id = :id')
                                ->setParameter('id', $winnerRecordId)
                                ->setMaxResults(1)
                                ->executeQuery()
                                ->fetchAssociative();
                            if (is_array($winnerForHistory) && $winnerForHistory !== []) {
                                (new WinnerStatusEmailService($this->mailer))->recordSentEmailInHistory(
                                    $connection,
                                    $winnerForHistory,
                                    $winnerRecordId,
                                    $winnersTable,
                                    $winnerStatus,
                                    (string) ($mailResult['template_id'] ?? ''),
                                    'prize_type_winner_email',
                                    $sentAt,
                                    'status_change'
                                );
                            }
                        }
                    }
                } catch (\Throwable $exception) {
                    // Email errors should not block scratchcard delivery.
                }

                try {
                    if ($winnerRecordId !== null && $winnerRecordId > 0) {
                        $winnerForStatus = $connection->createQueryBuilder()
                            ->select('*')
                            ->from($winnersTable)
                            ->where('id = :id')
                            ->setParameter('id', $winnerRecordId)
                            ->setMaxResults(1)
                            ->executeQuery()
                            ->fetchAssociative();

                        if (is_array($winnerForStatus) && $winnerForStatus !== []) {
                            $codeAssignmentService = new WinnerPrizeCodeAssignmentService();
                            $currentWinnerStatus = trim((string) ($winnerForStatus['stan'] ?? $winnerStatus));
                            $assignmentResult = $codeAssignmentService->assignConfiguredCodesToWinner($connection, $winnerForStatus);
                            $codeShortage = false;
                            if ($assignmentResult['success']) {
                                $winnerForStatus = $assignmentResult['winner'];
                                $codeShortage = filter_var($assignmentResult['code_shortage'] ?? false, FILTER_VALIDATE_BOOLEAN);
                                if ($codeShortage) {
                                    $currentWinnerStatus = trim((string) ($assignmentResult['effective_status_id'] ?? $winnerForStatus['stan'] ?? $currentWinnerStatus));
                                }
                            }

                            $statusEmailService = new WinnerStatusEmailService($this->mailer);
                            $this->sendWinnerStatusAdministratorEmail(
                                $connection,
                                $winnerForStatus,
                                $winnerRecordId,
                                '',
                                $currentWinnerStatus
                            );
                            $statusEmailService->sendForStatus(
                                $connection,
                                $winnerForStatus,
                                $winnerRecordId,
                                $winnersTable,
                                $currentWinnerStatus,
                                false
                            );
                        }
                    }
                } catch (\Throwable $exception) {
                    // Status e-mail and code assignment errors should not block scratchcard delivery.
                }
            }

            return new JsonResponse([
                'success' => true,
                'already_checked' => false,
                'is_winner' => $isWinner,
                'prize_name' => $isWinner ? ($prizeName !== '' ? $prizeName : null) : null,
                'voucher_code' => $isWinner ? ($voucherCode !== '' ? $voucherCode : null) : null,
                'scratchcard_image_url' => $isWinner ? $scratchcardImageUrl : null,
                'message' => $isWinner
                    ? '🎉 Gratulacje! Ten los wygrał.'
                    : '😕 Tym razem bez wygranej.',
            ]);
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            return new JsonResponse([
                'success' => false,
                'message' => 'Nie udało się sprawdzić wyniku. Spróbuj ponownie.',
            ], 500);
        }
    }

    /**
     * @param callable(string): bool $hasWinnerColumn
     */
    private function resolveScratchcardPrizeTypeIdForWinnerTicket(
        Connection $connection,
        string $winnersTable,
        array $ticket,
        callable $hasWinnerColumn
    ): int {
        if (!$hasWinnerColumn('rodzaj_wygranej') || !$hasWinnerColumn('zdrapka_w_loterii')) {
            return 0;
        }

        $ticketEntryId = (int) ($ticket['id'] ?? 0);
        if ($ticketEntryId <= 0) {
            return 0;
        }

        $queryBuilder = $connection->createQueryBuilder()
            ->select('rodzaj_wygranej')
            ->from($winnersTable)
            ->where('zdrapka_w_loterii = :ticketEntryId')
            ->andWhere('rodzaj_wygranej IS NOT NULL')
            ->setParameter('ticketEntryId', $ticketEntryId);

        if ($hasWinnerColumn('id')) {
            $queryBuilder->orderBy('id', 'DESC');
        }

        $prizeTypeId = $queryBuilder
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_scalar($prizeTypeId) && (int) $prizeTypeId > 0 ? (int) $prizeTypeId : 0;
    }

    /**
     * @param callable(string): bool $hasTicketColumn
     * @param callable(string): bool $hasScheduleColumn
     */
    private function resolveScratchcardPrizeTypeIdForTicket(
        Connection $connection,
        string $scheduleTable,
        array $ticket,
        callable $hasTicketColumn,
        callable $hasScheduleColumn
    ): int {
        if (!$hasScheduleColumn('rodzaj_nagrody')) {
            return 0;
        }

        if ($hasTicketColumn('win_schedule_id')) {
            $scheduleId = (int) ($ticket['win_schedule_id'] ?? 0);
            if ($scheduleId > 0) {
                $prizeTypeId = $connection->createQueryBuilder()
                    ->select('rodzaj_nagrody')
                    ->from($scheduleTable)
                    ->where('id = :id')
                    ->setParameter('id', $scheduleId)
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchOne();

                if (is_scalar($prizeTypeId) && (int) $prizeTypeId > 0) {
                    return (int) $prizeTypeId;
                }
            }
        }

        if ($hasScheduleColumn('granted_to_ticket_id')) {
            $ticketEntryId = (int) ($ticket['id'] ?? 0);
            if ($ticketEntryId > 0) {
                $queryBuilder = $connection->createQueryBuilder()
                    ->select('rodzaj_nagrody')
                    ->from($scheduleTable)
                    ->where('granted_to_ticket_id = :ticketEntryId')
                    ->setParameter('ticketEntryId', $ticketEntryId);

                if ($hasScheduleColumn('granted_at')) {
                    $queryBuilder->orderBy('granted_at', 'DESC');
                }
                $queryBuilder->addOrderBy('id', 'DESC');

                $prizeTypeId = $queryBuilder
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchOne();

                if (is_scalar($prizeTypeId) && (int) $prizeTypeId > 0) {
                    return (int) $prizeTypeId;
                }
            }
        }

        return 0;
    }

    private function fetchScratchcardPrizeType(Connection $connection, string $prizeTypeTable, int $prizeTypeId): array
    {
        if ($prizeTypeId <= 0) {
            return [];
        }

        $prizeTypeColumns = array_keys($connection->createSchemaManager()->listTableColumns($prizeTypeTable));
        $selectColumns = ['id'];
        foreach (['name', 'nazwa_nagrody_do_wyswietlenia', 'zdjecie_zdrapki', 'status_po_wygraniu_nagrody'] as $column) {
            if (in_array($column, $prizeTypeColumns, true)) {
                $selectColumns[] = $column;
            }
        }

        $prizeType = $connection->createQueryBuilder()
            ->select(...$selectColumns)
            ->from($prizeTypeTable)
            ->where('id = :id')
            ->setParameter('id', $prizeTypeId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($prizeType)) {
            return [];
        }

        $displayName = trim((string) ($prizeType['nazwa_nagrody_do_wyswietlenia'] ?? ''));
        if ($displayName === '') {
            $displayName = trim((string) ($prizeType['name'] ?? ''));
        }

        $scratchcardImageUrl = $this->normalizeScratchcardImageUrl((string) ($prizeType['zdjecie_zdrapki'] ?? ''));

        return [
            'id' => (int) ($prizeType['id'] ?? 0),
            'display_name' => $displayName !== '' ? $displayName : null,
            'scratchcard_image_url' => $scratchcardImageUrl,
            'status_po_wygraniu_nagrody' => trim((string) ($prizeType['status_po_wygraniu_nagrody'] ?? '')),
        ];
    }

    private function fetchScratchcardPrizeTypeByName(
        Connection $connection,
        string $prizeTypeTable,
        string $prizeName,
        string $lotteryId = ''
    ): array {
        $prizeTypeColumns = array_keys($connection->createSchemaManager()->listTableColumns($prizeTypeTable));
        $selectColumns = ['id'];
        foreach (['name', 'nazwa_nagrody_do_wyswietlenia', 'zdjecie_zdrapki', 'rodzaj_nagrody', 'loteria', 'status_po_wygraniu_nagrody'] as $column) {
            if (in_array($column, $prizeTypeColumns, true)) {
                $selectColumns[] = $column;
            }
        }

        $rows = $connection->createQueryBuilder()
            ->select(...$selectColumns)
            ->from($prizeTypeTable)
            ->executeQuery()
            ->fetchAllAssociative();

        $normalizedPrizeName = $this->normalizeScratchcardPrizeName($prizeName);
        $candidates = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $assignedTo = mb_strtolower(trim((string) ($row['rodzaj_nagrody'] ?? '')));
            if ($assignedTo !== '' && $assignedTo !== 'zdrapki') {
                continue;
            }

            $rowLotteryId = trim((string) ($row['loteria'] ?? ''));
            if ($lotteryId !== '' && $rowLotteryId !== '' && $rowLotteryId !== $lotteryId) {
                continue;
            }

            $candidates[] = $row;

            if ($normalizedPrizeName === '') {
                continue;
            }

            $normalizedDisplayName = $this->normalizeScratchcardPrizeName((string) ($row['nazwa_nagrody_do_wyswietlenia'] ?? ''));
            $normalizedName = $this->normalizeScratchcardPrizeName((string) ($row['name'] ?? ''));
            if ($normalizedPrizeName === $normalizedDisplayName || $normalizedPrizeName === $normalizedName) {
                return $this->normalizeScratchcardPrizeTypeRow($row);
            }
        }

        if (count($candidates) === 1) {
            return $this->normalizeScratchcardPrizeTypeRow($candidates[0]);
        }

        return [];
    }

    private function normalizeScratchcardPrizeTypeRow(array $prizeType): array
    {
        $displayName = trim((string) ($prizeType['nazwa_nagrody_do_wyswietlenia'] ?? ''));
        if ($displayName === '') {
            $displayName = trim((string) ($prizeType['name'] ?? ''));
        }

        return [
            'id' => (int) ($prizeType['id'] ?? 0),
            'display_name' => $displayName !== '' ? $displayName : null,
            'scratchcard_image_url' => $this->normalizeScratchcardImageUrl((string) ($prizeType['zdjecie_zdrapki'] ?? '')),
            'status_po_wygraniu_nagrody' => trim((string) ($prizeType['status_po_wygraniu_nagrody'] ?? '')),
        ];
    }

    private function normalizeScratchcardPrizeName(string $value): string
    {
        $value = strip_tags($value);
        $value = str_replace(["\xc2\xa0", '&nbsp;'], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }

    private function normalizeScratchcardImageUrl(string $imageUrl): ?string
    {
        $imageUrl = trim($imageUrl);
        if ($imageUrl === '') {
            return null;
        }

        if (
            preg_match('#^(?:https?:)?//#i', $imageUrl) === 1
            || preg_match('#^(?:data|blob):#i', $imageUrl) === 1
        ) {
            return $imageUrl;
        }

        return '/' . ltrim($imageUrl, '/');
    }


    private function resolveCurrentLottery(array $lotterySettings = []): ?array
    {
        $now = new \DateTimeImmutable();
        $settingsStartDate = $this->resolveLotteryStartDate($lotterySettings['loteria_data_startu'] ?? null);
        $settingsEndDate = $this->resolveLotteryEndDate($lotterySettings['loteria_data_zakonczenia'] ?? null);
        if ($settingsStartDate === null) {
            return null;
        }

        if ($settingsStartDate > $now) {
            return null;
        }

        if ($settingsEndDate !== null && $settingsEndDate < $now) {
            return null;
        }

        return [
            'id' => 'default',
            'type' => 'losowanie_zdrapki',
            'start_date' => $settingsStartDate->format('Y-m-d H:i:s'),
            'end_date' => $settingsEndDate?->format('Y-m-d H:i:s'),
        ];
    }

    private function resolveDefaultScratchLottery(): array
    {
        $lotterySettings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
        $settingsStartDate = $this->resolveLotteryStartDate($lotterySettings['loteria_data_startu'] ?? null);
        $settingsEndDate = $this->resolveLotteryEndDate($lotterySettings['loteria_data_zakonczenia'] ?? null);

        return [
            'id' => 'default',
            'type' => 'losowanie_zdrapki',
            'start_date' => $settingsStartDate instanceof \DateTimeImmutable ? $settingsStartDate->format('Y-m-d H:i:s') : null,
            'end_date' => $settingsEndDate instanceof \DateTimeImmutable ? $settingsEndDate->format('Y-m-d H:i:s') : null,
        ];
    }

    private function resolveUpcomingLottery(array $lotterySettings = []): ?array
    {
        $now = new \DateTimeImmutable();
        $settingsStartDate = $this->resolveLotteryStartDate($lotterySettings['loteria_data_startu'] ?? null);
        if ($settingsStartDate === null || $settingsStartDate <= $now) {
            return null;
        }

        return [
            'id' => 'default',
            'start_date' => $settingsStartDate->format('Y-m-d H:i:s'),
        ];
    }

    private function resolveEndedLottery(array $lotterySettings = []): ?array
    {
        $now = new \DateTimeImmutable();
        $settingsStartDate = $this->resolveLotteryStartDate($lotterySettings['loteria_data_startu'] ?? null);
        $settingsEndDate = $this->resolveLotteryEndDate($lotterySettings['loteria_data_zakonczenia'] ?? null);
        if ($settingsEndDate === null || $settingsEndDate >= $now) {
            return null;
        }

        return [
            'id' => 'default',
            'start_date' => $settingsStartDate?->format('Y-m-d H:i:s'),
            'end_date' => $settingsEndDate->format('Y-m-d H:i:s'),
        ];
    }

    private function resolveLotteryStartDate(mixed $startRaw): ?\DateTimeImmutable
    {
        return $this->resolveLotteryBoundaryDate($startRaw, '00:00:00');
    }

    private function resolveLotteryEndDate(mixed $endRaw): ?\DateTimeImmutable
    {
        return $this->resolveLotteryBoundaryDate($endRaw, '23:59:59');
    }

    private function resolveLotteryBoundaryDate(mixed $rawValue, ?string $defaultTime): ?\DateTimeImmutable
    {
        if (!is_scalar($rawValue) || trim((string) $rawValue) === '') {
            return null;
        }

        $value = trim((string) $rawValue);
        if ($defaultTime !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $value .= ' ' . $defaultTime;
        }

        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
            'd.m.Y H:i:s',
            'd.m.Y H:i',
            'd.m.Y',
        ];

        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof \DateTimeImmutable) {
                return $date;
            }
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    public function winnerForm(Request $request, array $parameters): Response
    {
        if (($ageGateResponse = $this->requireAgeVerification($request)) !== null) {
            return $ageGateResponse;
        }

        $token = trim((string) ($parameters['token'] ?? ''));
        if ($token === '') {
            return new RedirectResponse('/loteria');
        }

        $winnersDefinition = $this->resolveModuleDefinition('zwyciezcy');
        $scratchcardTicketDefinition = $this->resolveModuleDefinition('loteria_zdrapki');
        $ticketDefinition = $this->resolveModuleDefinition('loteria_losy');
        $sellerTicketDefinition = $this->resolveModuleDefinition('sprzedawcy_losy');
        $submissionDefinition = $this->resolveModuleDefinition('loteria_zgloszenia');

        $winnersTable = $winnersDefinition['database']['table'] ?? null;
        $scratchcardTicketTable = $scratchcardTicketDefinition['database']['table'] ?? null;
        $ticketTable = $ticketDefinition['database']['table'] ?? null;
        $sellerTicketTable = $sellerTicketDefinition['database']['table'] ?? null;
        $submissionTable = $submissionDefinition['database']['table'] ?? null;

        $renderWinnerPage = function (?string $selectedCenterId, ?string $status, ?string $message, array $errors, array $formData, string $submissionTemplate = 'frontend/loteria/winner_submission.html.twig', bool $ticketRequiresReceipt = false, bool $winnerRequiresPesel = true, bool $winnerOnlyNameSurname = false, bool $winnerRequiresShirtSize = false, string $winnerFormType = ''): Response {
            $context = $this->buildLoteriaM1Context(new Request(), [], $selectedCenterId);

            return $this->render($this->resolveRegistrationPageTemplate($context['settings'] ?? null), array_merge($context, [
                'submissionTemplate' => $submissionTemplate,
                'registrationTitle' => 'Formularz zwycięzcy',
                'registrationInfo' => '',
                'status' => $status,
                'message' => $message,
                'errors' => $errors,
                'formData' => $formData,
                'ticketRequiresReceipt' => $ticketRequiresReceipt,
                'winnerRequiresPesel' => $winnerRequiresPesel,
                'winnerOnlyNameSurname' => $winnerOnlyNameSurname,
                'winnerRequiresShirtSize' => $winnerRequiresShirtSize,
                'winnerFormType' => $winnerFormType,
                'winnerPageOnlyRegistration' => false,
            ]));
        };

        if (
            !is_string($winnersTable) || $winnersTable === ''
            || !is_string($scratchcardTicketTable) || $scratchcardTicketTable === ''
            || !is_string($ticketTable) || $ticketTable === ''
            || !is_string($submissionTable) || $submissionTable === ''
        ) {
            return $renderWinnerPage(null, 'error', 'Brak konfiguracji modułów loterii.', [], []);
        }

        $connection = $this->entityManager->getConnection();
        $winner = $connection->createQueryBuilder()
            ->select('*')
            ->from($winnersTable)
            ->where('token_formularza = :token')
            ->setParameter('token', $token)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        $winnerTable = $winnersTable;
        $winnerKind = trim((string) ($winner['rodzaj'] ?? ''));
        $winnerType = $winnerKind === 'los' ? 'ticket' : 'scratchcard';
        $formTemplate = 'frontend/loteria/winner_submission.html.twig';

        if (!$winner) {
            return $renderWinnerPage(null, 'error', 'Nie znaleziono wygranego losu dla tego linku.', [], [], $formTemplate);
        }

        $winnerTicketField = $winnerType === 'scratchcard' ? 'zdrapka_w_loterii' : 'kupon_w_loterii';
        $sourceTicketTable = $winnerType === 'scratchcard' ? $scratchcardTicketTable : $ticketTable;
        if ($winnerType === 'ticket' && (int) ($winner[$winnerTicketField] ?? 0) <= 0 && is_string($sellerTicketTable) && $sellerTicketTable !== '') {
            $sourceTicketTable = $sellerTicketTable;
            $winnerType = 'seller_ticket';
        }
        if (!is_string($sourceTicketTable) || $sourceTicketTable === '') {
            return $renderWinnerPage(null, 'error', 'Brak konfiguracji modułów loterii.', [], [], $formTemplate);
        }
        $ticket = $connection->createQueryBuilder()
            ->select('*')
            ->from($sourceTicketTable)
            ->where('id = :id')
            ->setParameter('id', (int) ($winner[$winnerTicketField] ?? 0))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!$ticket || ($winnerType === 'scratchcard' && empty($ticket['wygrany']))) {
            return $renderWinnerPage(null, 'error', 'Nie znaleziono wygranego losu dla tego linku.', [], [], $formTemplate);
        }

        $winnerFormType = $winnerType === 'scratchcard' ? 'receipt' : 'personal_data';
        $prizeTypeId = trim((string) ($winner['rodzaj_wygranej'] ?? ''));
        if ($prizeTypeId !== '') {
            $prizeType = $connection->createQueryBuilder()
                ->select('form_type')
                ->from('rodzaje_nagrod_entries')
                ->where('id = :id')
                ->setParameter('id', $prizeTypeId)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
            $resolvedFormType = trim((string) ($prizeType['form_type'] ?? ''));
            if (in_array($resolvedFormType, ['personal_data_receipt', 'personal_data', 'personal_data_shipping', 'bank_account_with_tax', 'bank_account_without_tax', 'name_surname', 'no_form'], true)) {
                $winnerFormType = $resolvedFormType;
            }
        }

        $formTemplate = match ($winnerFormType) {
            'personal_data', 'personal_data_receipt', 'personal_data_shipping', 'bank_account_with_tax', 'bank_account_without_tax', 'name_surname' => 'frontend/loteria/winner_submission_ticket.html.twig',
            default => 'frontend/loteria/winner_submission.html.twig',
        };
        $ticketRequiresReceipt = $winnerFormType === 'personal_data_receipt';
        $winnerRequiresPesel = in_array($winnerFormType, ['personal_data', 'personal_data_receipt', 'bank_account_with_tax'], true);
        $winnerOnlyNameSurname = in_array($winnerFormType, ['name_surname', 'bank_account_without_tax'], true);
        $winnerRequiresShirtSize = $winnerFormType === 'personal_data_shipping';

        $alreadySubmitted = $this->isWinnerFormAlreadySubmitted($winner);
        $winnerFormExpiry = $this->resolveWinnerFormExpiry($winner);

        $winnerCenterId = ($winner['centrum_handlowe'] ?? null) ?: ($ticket['centrum_handlowe'] ?? null);
        if (!is_scalar($winnerCenterId)) {
            $winnerCenterId = null;
        }
        $winnerCenterId = $winnerCenterId === null ? null : trim((string) $winnerCenterId);
        if ($winnerCenterId === '') {
            $winnerCenterId = null;
        }

        if ($alreadySubmitted) {
            return $renderWinnerPage($winnerCenterId, 'success', 'Twoje dane zostały już zapisane. Dziękujemy!', [], [], $formTemplate, $ticketRequiresReceipt, $winnerRequiresPesel, $winnerOnlyNameSurname, $winnerRequiresShirtSize);
        }

        if ($winnerFormExpiry !== null && $winnerFormExpiry < new \DateTimeImmutable()) {
            return $renderWinnerPage(
                $winnerCenterId,
                'error',
                sprintf('Czas na wypełnienie formularza zwycięzcy minął %s.', $winnerFormExpiry->format('d.m.Y o H:i')),
                [],
                [],
                $formTemplate,
                $ticketRequiresReceipt,
                $winnerRequiresPesel,
                $winnerOnlyNameSurname,
                $winnerRequiresShirtSize,
                $winnerFormType
            );
        }

        $submissionIdForWinner = (int) ($winner['zgloszenie'] ?? 0);
        if ($submissionIdForWinner <= 0) {
            $submissionIdForWinner = (int) ($ticket['zgloszenie'] ?? 0);
        }

        $submission = $connection->createQueryBuilder()
            ->select('*')
            ->from($submissionTable)
            ->where('id = :submissionId')
            ->setParameter('submissionId', $submissionIdForWinner)
            ->executeQuery()
            ->fetchAssociative();

        if ($submission) {
            $submissionCenterId = $submission['centrum_handlowe'] ?? null;
            if (is_scalar($submissionCenterId) && trim((string) $submissionCenterId) !== '') {
                $winnerCenterId = trim((string) $submissionCenterId);
            }
        }

        $systemEmail = $this->firstFilledString(
            $winner['email'] ?? null,
            $submission['email'] ?? null,
            $ticket['email'] ?? null,
            $ticket['seller_email'] ?? null
        );
        $systemPhone = $this->firstFilledString(
            $winner['telefon'] ?? null,
            $winner['phone'] ?? null,
            $submission['phone'] ?? null,
            $submission['telefon'] ?? null,
            $ticket['phone'] ?? null,
            $ticket['telefon'] ?? null,
            $ticket['seller_phone'] ?? null
        );

        $errors = [];
        $status = null;
        $message = null;
        if ($winnerFormType === 'no_form') {
            return $renderWinnerPage($winnerCenterId, 'success', 'Ta nagroda nie wymaga uzupełnienia formularza. Dziękujemy!', [], [], $formTemplate, false, $winnerRequiresPesel, $winnerOnlyNameSurname);
        }

        $formData = [
            'email' => $systemEmail,
            'telefon' => $systemPhone,
        ];

        if (in_array($winnerFormType, ['personal_data', 'personal_data_receipt', 'personal_data_shipping', 'bank_account_with_tax', 'bank_account_without_tax', 'name_surname'], true)) {
            $formData += [
                'imie' => trim((string) ($winner['imie'] ?? '')),
                'nazwisko' => trim((string) ($winner['nazwisko'] ?? '')),
                'email' => $systemEmail,
                'telefon' => $systemPhone,
                'ulica' => trim((string) ($winner['ulica'] ?? '')),
                'numer_domu' => trim((string) ($winner['numer_domu'] ?? '')),
                'numer_mieszkania' => trim((string) ($winner['numer_mieszkania'] ?? '')),
                'kod_pocztowy' => trim((string) ($winner['kod_pocztowy'] ?? '')),
                'miejscowosc' => trim((string) ($winner['miejscowosc'] ?? '')),
                'numer_pesel' => trim((string) ($winner['numer_pesel'] ?? '')),
                'rodzaj_dokumentu' => trim((string) ($winner['rodzaj_dokumentu'] ?? '')),
                'seria_i_numer_dokumentu_tozsamosci' => trim((string) ($winner['seria_i_numer_dokumentu_tozsamosci'] ?? '')),
                'data_urodzenia' => trim((string) ($winner['data_urodzenia'] ?? '')),
                'obywatelstwo' => trim((string) ($winner['obywatelstwo'] ?? '')),
                'numer_rachunku_bankowego' => trim((string) ($winner['numer_rachunku_bankowego'] ?? '')),
                'brak_pesel' => '',
                'rachunek' => trim((string) ($winner['rachunek'] ?? '')),
                'rozmiar_koszulki' => trim((string) ($winner['rozmiar_koszulki'] ?? '')),
            ];
        }

        if ($request->isMethod('POST')) {
            if ($winnerFormType === 'receipt') {
                $receiptFile = $request->files->get('rachunek');
                if (!$receiptFile instanceof UploadedFile) {
                    $errors[] = 'Rachunek w formie zdjęcia jest wymagany.';
                } elseif (!$receiptFile->isValid()) {
                    $errors[] = 'Nie udało się wczytać rachunku.';
                } elseif (!$this->isImageUploadAllowed($receiptFile)) {
                    $errors[] = 'Rachunek musi być plikiem graficznym.';
                }

                if ($errors === []) {
                    $storedPath = $this->storeWinnerReceipt($receiptFile);
                    if ($storedPath === null) {
                        $errors[] = 'Nie udało się zapisać rachunku.';
                    } else {
                        $lotteryId = $submission ? ($submission['loteria'] ?? null) : ($ticket['loteria'] ?? null);
                        $centerId = $submission ? ($submission['centrum_handlowe'] ?? null) : ($ticket['centrum_handlowe'] ?? null);
                        $submissionEmail = $submission ? (string) ($submission['email'] ?? '') : '';
                        $submissionPhone = $submission ? (string) ($submission['phone'] ?? '') : '';
                        $submissionFirstName = $submission ? trim((string) ($submission['imie'] ?? '')) : '';
                        $submissionLastName = $submission ? trim((string) ($submission['nazwisko'] ?? '')) : '';
                        $submissionId = $submission ? (int) ($submission['id'] ?? 0) : (int) ($ticket['zgloszenie'] ?? 0);
                        $submittedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                        $clientIp = trim((string) ($request->getClientIp() ?? ''));
                        $userAgent = trim((string) $request->headers->get('User-Agent', ''));
                        $purchaseDocumentNumber = trim((string) ($submission['purchase_document_number'] ?? ($winner['numer_dowodu_zakupu'] ?? '')));
                        $purchaseAmount = $submission['purchase_amount'] ?? ($winner['kwota_z_rachunku'] ?? null);
                        if (is_string($purchaseAmount)) {
                            $purchaseAmount = trim($purchaseAmount);
                        }
                        $nextWinnerStatus = $this->resolveNextWinnerStatusAfterFormSubmission(
                            $connection,
                            trim((string) ($winner['stan'] ?? ''))
                        );

                        $connection->update($winnerTable, [
                            'imie' => $submissionFirstName,
                            'nazwisko' => $submissionLastName,
                            'email' => trim($submissionEmail !== '' ? $submissionEmail : (string) ($ticket['email'] ?? '')),
                            'telefon' => trim($submissionPhone !== '' ? $submissionPhone : (string) ($ticket['phone'] ?? '')),
                            'rodzaj' => 'zdrapka',
                            'zgloszenie' => $submissionId > 0 ? $submissionId : null,
                            'kupon_w_loterii' => null,
                            'zdrapka_w_loterii' => (int) $ticket['id'],
                            'loteria' => $lotteryId !== '' ? $lotteryId : null,
                            'centrum_handlowe' => $centerId !== '' ? $centerId : null,
                            'numer_dowodu_zakupu' => $purchaseDocumentNumber !== '' ? $purchaseDocumentNumber : null,
                            'kwota_z_rachunku' => $purchaseAmount !== '' ? $purchaseAmount : null,
                            'rachunek' => $storedPath,
                            'zgloszenie_wypelnione_at' => $submittedAt,
                            'zgloszenie_ip' => $clientIp !== '' ? $clientIp : null,
                            'zgloszenie_user_agent' => $userAgent !== '' ? $userAgent : null,
                            'stan' => $nextWinnerStatus,
                        ], [
                            'id' => (int) $winner['id'],
                        ]);

                        $this->sendWinnerStatusAdministratorEmail(
                            $connection,
                            array_merge($winner, [
                                'imie' => $submissionFirstName,
                                'nazwisko' => $submissionLastName,
                                'email' => trim($submissionEmail !== '' ? $submissionEmail : (string) ($ticket['email'] ?? '')),
                                'telefon' => trim($submissionPhone !== '' ? $submissionPhone : (string) ($ticket['phone'] ?? '')),
                                'numer_dowodu_zakupu' => $purchaseDocumentNumber,
                                'stan' => $nextWinnerStatus,
                            ]),
                            (int) $winner['id'],
                            trim((string) ($winner['stan'] ?? '')),
                            $nextWinnerStatus
                        );

                        $this->sendReceiptSentEmail(
                            trim($submissionEmail !== '' ? $submissionEmail : (string) ($ticket['email'] ?? '')),
                            trim($submissionPhone !== '' ? $submissionPhone : (string) ($ticket['phone'] ?? '')),
                            $purchaseDocumentNumber
                        );

                        $status = 'success';
                        $message = 'Dziękujemy! Twoje dane zostały zapisane.';
                    }
                }
            } else {
                $formData = [
                    'imie' => trim((string) $request->request->get('imie', '')),
                    'nazwisko' => trim((string) $request->request->get('nazwisko', '')),
                    'email' => $systemEmail,
                    'telefon' => $systemPhone,
                    'ulica' => trim((string) $request->request->get('ulica', '')),
                    'numer_domu' => trim((string) $request->request->get('numer_domu', '')),
                    'numer_mieszkania' => trim((string) $request->request->get('numer_mieszkania', '')),
                    'kod_pocztowy' => trim((string) $request->request->get('kod_pocztowy', '')),
                    'miejscowosc' => trim((string) $request->request->get('miejscowosc', '')),
                    'numer_pesel' => preg_replace('/\D+/', '', trim((string) $request->request->get('numer_pesel', ''))) ?? '',
                    'rodzaj_dokumentu' => trim((string) $request->request->get('rodzaj_dokumentu', '')),
                    'seria_i_numer_dokumentu_tozsamosci' => trim((string) $request->request->get('seria_i_numer_dokumentu_tozsamosci', '')),
                    'data_urodzenia' => trim((string) $request->request->get('data_urodzenia', '')),
                    'obywatelstwo' => trim((string) $request->request->get('obywatelstwo', '')),
                    'brak_pesel' => $request->request->get('brak_pesel', '') === '1' ? '1' : '',
                    'numer_rachunku_bankowego' => preg_replace('/\D+/', '', trim((string) $request->request->get('numer_rachunku_bankowego', ''))) ?? '',
                    'rozmiar_koszulki' => trim((string) $request->request->get('rozmiar_koszulki', '')),
                ];

                if ($formData['imie'] === '') {
                    $errors[] = 'Imię jest wymagane.';
                }
                if ($formData['nazwisko'] === '') {
                    $errors[] = 'Nazwisko jest wymagane.';
                }
                if (!$winnerOnlyNameSurname) {
                    if ($formData['ulica'] === '') {
                        $errors[] = 'Ulica jest wymagana.';
                    }
                    if ($formData['numer_domu'] === '') {
                        $errors[] = 'Numer domu jest wymagany.';
                    }
                    if ($formData['kod_pocztowy'] === '') {
                        $errors[] = 'Kod pocztowy jest wymagany.';
                    }
                    if ($formData['miejscowosc'] === '') {
                        $errors[] = 'Miejscowość jest wymagana.';
                    }
                }
                if ($winnerRequiresShirtSize && !in_array($formData['rozmiar_koszulki'], ['S', 'M', 'L', 'XL'], true)) {
                    $errors[] = 'Rozmiar koszulki jest wymagany.';
                }
                $winnerRequiresBankAccount = in_array($winnerFormType, ['bank_account_with_tax', 'bank_account_without_tax'], true);
                if ($winnerRequiresBankAccount && !preg_match('/^\d{26}$/', $formData['numer_rachunku_bankowego'])) {
                    $errors[] = 'Numer rachunku bankowego musi składać się z 26 cyfr.';
                }
                $hasNoPesel = false;
                if ($winnerRequiresPesel) {
                    if ($winnerFormType !== 'bank_account_with_tax' && $formData['rodzaj_dokumentu'] === '') {
                        $errors[] = 'Rodzaj dokumentu jest wymagany.';
                    }
                    if ($formData['seria_i_numer_dokumentu_tozsamosci'] === '') {
                        $errors[] = 'Seria i numer dokumentu tożsamości są wymagane.';
                    }

                    $hasNoPesel = $formData['brak_pesel'] === '1';
                    $hasPesel = $formData['numer_pesel'] !== '';
                    if ($hasNoPesel) {
                        if ($formData['data_urodzenia'] === '') {
                            $errors[] = 'Data urodzenia jest wymagana, jeżeli nie podano numeru PESEL.';
                        }
                        if ($formData['obywatelstwo'] === '') {
                            $errors[] = 'Obywatelstwo jest wymagane, jeżeli nie podano numeru PESEL.';
                        }
                    } elseif (!$hasPesel) {
                        $errors[] = 'Numer PESEL jest wymagany, jeżeli nie zaznaczono opcji braku numeru PESEL.';
                    } elseif ($winnerFormType === 'bank_account_with_tax' && !preg_match('/^\d{11}$/', $formData['numer_pesel'])) {
                        $errors[] = 'Numer PESEL musi składać się z 11 cyfr.';
                    } elseif ($winnerFormType !== 'bank_account_with_tax' && !$this->isValidPesel($formData['numer_pesel'])) {
                        $errors[] = 'Podano nieprawidłowy numer PESEL.';
                    }
                }

                $receiptPathToSave = trim((string) ($winner['rachunek'] ?? ''));
                if ($ticketRequiresReceipt) {
                    $receiptFile = $request->files->get('rachunek');
                    if (!$receiptFile instanceof UploadedFile) {
                        $errors[] = 'Zdjęcie paragonu jest wymagane.';
                    } elseif (!$receiptFile->isValid()) {
                        $errors[] = 'Nie udało się wczytać zdjęcia paragonu.';
                    } elseif (!$this->isImageUploadAllowed($receiptFile)) {
                        $errors[] = 'Paragon musi być plikiem graficznym.';
                    } else {
                        $storedPath = $this->storeWinnerReceipt($receiptFile);
                        if ($storedPath === null) {
                            $errors[] = 'Nie udało się zapisać zdjęcia paragonu.';
                        } else {
                            $receiptPathToSave = $storedPath;
                        }
                    }
                }

                if ($errors === []) {
                    $submittedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                    $clientIp = trim((string) ($request->getClientIp() ?? ''));
                    $userAgent = trim((string) $request->headers->get('User-Agent', ''));

                    $nextWinnerStatus = $this->resolveNextWinnerStatusAfterFormSubmission(
                        $connection,
                        trim((string) ($winner['stan'] ?? ''))
                    );

                    $winnerUpdateData = [
                        'imie' => $formData['imie'],
                        'nazwisko' => $formData['nazwisko'],
                        'email' => trim((string) ($formData['email'] ?? '')) !== '' ? trim((string) $formData['email']) : null,
                        'telefon' => trim((string) ($formData['telefon'] ?? '')) !== '' ? trim((string) $formData['telefon']) : null,
                        'zgloszenie_wypelnione_at' => $submittedAt,
                        'zgloszenie_ip' => $clientIp !== '' ? $clientIp : null,
                        'zgloszenie_user_agent' => $userAgent !== '' ? $userAgent : null,
                        'stan' => $nextWinnerStatus,
                    ];

                    if ($ticketRequiresReceipt) {
                        $winnerUpdateData['rachunek'] = $receiptPathToSave !== '' ? $receiptPathToSave : null;
                    }

                    if (!$winnerOnlyNameSurname) {
                        $winnerUpdateData += [
                            'ulica' => $formData['ulica'],
                            'numer_domu' => $formData['numer_domu'],
                            'numer_mieszkania' => $formData['numer_mieszkania'] !== '' ? $formData['numer_mieszkania'] : null,
                            'kod_pocztowy' => $formData['kod_pocztowy'],
                            'miejscowosc' => $formData['miejscowosc'],
                        ];
                    }

                    if ($winnerRequiresShirtSize) {
                        $winnerUpdateData['rozmiar_koszulki'] = $formData['rozmiar_koszulki'];
                    }

                    if ($winnerRequiresBankAccount) {
                        $winnerUpdateData['numer_rachunku_bankowego'] = $formData['numer_rachunku_bankowego'];
                    }

                    if ($winnerRequiresPesel) {
                        $winnerUpdateData += [
                            'numer_pesel' => $hasNoPesel ? null : $formData['numer_pesel'],
                            'seria_i_numer_dokumentu_tozsamosci' => $formData['seria_i_numer_dokumentu_tozsamosci'],
                            'data_urodzenia' => $hasNoPesel ? ($formData['data_urodzenia'] !== '' ? $formData['data_urodzenia'] : null) : null,
                            'obywatelstwo' => $hasNoPesel ? ($formData['obywatelstwo'] !== '' ? $formData['obywatelstwo'] : null) : null,
                        ];
                        if ($winnerFormType !== 'bank_account_with_tax') {
                            $winnerUpdateData['rodzaj_dokumentu'] = $formData['rodzaj_dokumentu'];
                        }
                    }

                    $connection->update($winnerTable, $winnerUpdateData, [
                        'id' => (int) $winner['id'],
                    ]);

                    $this->sendWinnerStatusAdministratorEmail(
                        $connection,
                        array_merge($winner, $winnerUpdateData),
                        (int) $winner['id'],
                        trim((string) ($winner['stan'] ?? '')),
                        $nextWinnerStatus
                    );

                    $status = 'success';
                    $message = 'Dziękujemy! Twoje dane zostały zapisane.';
                }
            }
        }

        return $renderWinnerPage($winnerCenterId, $status, $message, $errors, $formData, $formTemplate, $ticketRequiresReceipt, $winnerRequiresPesel, $winnerOnlyNameSurname, $winnerRequiresShirtSize, $winnerFormType);
    }

    /** @param array<string, mixed> $winner */
    private function sendWinnerStatusAdministratorEmail(
        Connection $connection,
        array $winner,
        int $winnerId,
        string $previousStatus,
        string $newStatus
    ): void {
        $normalizedNewStatus = trim($newStatus);
        if ($winnerId <= 0 || $normalizedNewStatus === '' || trim($previousStatus) === $normalizedNewStatus) {
            return;
        }

        try {
            (new WinnerStatusEmailService($this->mailer))->sendToAdministratorForStatus(
                $connection,
                $winner,
                $winnerId,
                $normalizedNewStatus
            );
        } catch (\Throwable) {
            // Administrator notification errors must not block the winner form submission.
        }
    }

    private function firstFilledString(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function resolveNextWinnerStatusAfterFormSubmission(Connection $connection, string $currentStatus): string
    {
        $normalizedCurrentStatus = trim($currentStatus);

        try {
            $statusRows = $connection->createQueryBuilder()
                ->select('id')
                ->from('ustawienia_statusow')
                ->orderBy('position', 'ASC')
                ->addOrderBy('id', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Throwable $exception) {
            return $normalizedCurrentStatus;
        }

        $orderedStatusIds = [];
        foreach ($statusRows as $statusRow) {
            $statusId = trim((string) ($statusRow['id'] ?? ''));
            if ($statusId !== '') {
                $orderedStatusIds[] = $statusId;
            }
        }

        if ($orderedStatusIds === []) {
            return $normalizedCurrentStatus;
        }

        if ($normalizedCurrentStatus === '') {
            return $orderedStatusIds[0];
        }

        $currentStatusIndex = array_search($normalizedCurrentStatus, $orderedStatusIds, true);
        if ($currentStatusIndex === false) {
            return $normalizedCurrentStatus;
        }

        return $orderedStatusIds[$currentStatusIndex + 1] ?? $normalizedCurrentStatus;
    }

    private function isValidPesel(string $pesel): bool
    {
        if (!preg_match('/^\d{11}$/', $pesel)) {
            return false;
        }

        $weights = [1, 3, 7, 9, 1, 3, 7, 9, 1, 3];
        $sum = 0;
        foreach ($weights as $index => $weight) {
            $sum += ((int) $pesel[$index]) * $weight;
        }

        $controlDigit = (10 - ($sum % 10)) % 10;

        return $controlDigit === (int) $pesel[10];
    }

    private function resolveWinnerFormExpiry(array $winner): ?\DateTimeImmutable
    {
        $settings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];

        return (new WinnerFormReminderService($this->mailer))->resolveFormExpiry($winner, $settings);
    }

    private function isWinnerFormAlreadySubmitted(array $winner): bool
    {
        $submittedAtRaw = trim((string) ($winner['zgloszenie_wypelnione_at'] ?? ''));
        if ($submittedAtRaw === '') {
            return false;
        }

        if (preg_match('/^0{4}-0{2}-0{2}(?:\s+0{2}:0{2}:0{2})?$/', $submittedAtRaw) === 1) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $options
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchActiveShoppingCenters(array $criteria = [], array $options = []): array
    {
        $centers = $this->fetchModuleEntries('centra_handlowe', $criteria, $options);

        return array_values(array_filter($centers, fn (array $center): bool => $this->isShoppingCenterActive($center)));
    }

    /**
     * @param array<string, mixed>|null $currentLottery
     *
     * @return array<int, array{id: string, name: string, city: string}>
     */
    private function resolveLotteryCenters(?array $currentLottery): array
    {
        if ($currentLottery === null) {
            return [];
        }

        $centerIds = $this->decodeRelationIds($currentLottery['centra_handlowe'] ?? null);
        if ($centerIds === []) {
            return [];
        }

        $centers = $this->fetchActiveShoppingCenters(['id' => $centerIds]);
        if ($centers === []) {
            return [];
        }

        $centersById = [];
        foreach ($centers as $center) {
            $centerId = trim((string) ($center['id'] ?? ''));
            if ($centerId === '') {
                continue;
            }
            $centersById[$centerId] = [
                'id' => $centerId,
                'name' => trim((string) ($center['name'] ?? '')),
                'city' => trim((string) ($center['city'] ?? '')),
            ];
        }

        $orderedCenters = [];
        foreach ($centerIds as $centerId) {
            if (isset($centersById[$centerId])) {
                $orderedCenters[] = $centersById[$centerId];
            }
        }

        return $orderedCenters;
    }

    private function isShoppingCenterActive(array $center): bool
    {
        $activeValue = trim((string) ($center['aktywne'] ?? ''));
        if ($activeValue === '') {
            return false;
        }

        return in_array(mb_strtolower($activeValue), ['tak', '1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<int, array{id: string, name: string, city: string}> $lotteryCenters
     *
     * @return array<string, array<int, array{id: string, name: string}>>
     */
    private function resolveStoreOptionsByCenter(array $lotteryCenters): array
    {
        $centerIds = array_column($lotteryCenters, 'id');
        if ($centerIds === []) {
            return [];
        }

        $stores = $this->fetchModuleEntries('lista_sklepow', ['centrum_handlowe' => $centerIds, 'active' => 1]);
        $storesByCenter = [];
        foreach ($stores as $store) {
            $storeId = trim((string) ($store['id'] ?? ''));
            $centerId = trim((string) ($store['centrum_handlowe'] ?? ''));
            if ($storeId === '' || $centerId === '') {
                continue;
            }

            $storesByCenter[$centerId][] = [
                'id' => $storeId,
                'name' => trim((string) ($store['nazwa_sklepu'] ?? '')),
            ];
        }

        foreach ($storesByCenter as $centerId => $centerStores) {
            usort($centerStores, static function (array $left, array $right): int {
                return strcmp(mb_strtolower($left['name']), mb_strtolower($right['name']));
            });
            $storesByCenter[$centerId] = $centerStores;
        }

        return $storesByCenter;
    }


    private function resolveSelectedCenterIdFromRequest(Request $request, array $allCenters, array $parameters = [], array $data = []): string
    {
        $selectedCenterId = trim((string) ($data['centrum'] ?? $request->query->get('centrum', '')));
        if ($selectedCenterId !== '' && $this->resolveCenterById($allCenters, $selectedCenterId) !== null) {
            return $selectedCenterId;
        }

        return '';
    }


    private function buildSellerRegistrationUrl(string $centerId, array $allCenters = []): string
    {
        if ($centerId === '') {
            return '/sprzedawcy';
        }

        $center = $this->resolveCenterById($allCenters, $centerId);
        if ($center === null) {
            return '/sprzedawcy';
        }

        $slug = $this->buildCenterSlug($center);

        return '/sprzedawcy/' . urlencode($slug);
    }

    private function appendFragmentToUrl(string $url, string $fragment): string
    {
        $url = trim($url);
        $fragment = trim($fragment);
        if ($url === '' || $fragment === '') {
            return $url;
        }

        $hashPosition = strpos($url, '#');
        if ($hashPosition !== false) {
            $url = substr($url, 0, $hashPosition);
        }

        return $url . '#' . rawurlencode($fragment);
    }

    private function resolveSellerCenterContextId(Request $request, array $allCenters, array $parameters = [], array $data = []): string
    {
        $contextCenterId = trim((string) ($data['centrum'] ?? $request->query->get('centrum', '')));
        if ($contextCenterId !== '' && $this->resolveCenterById($allCenters, $contextCenterId) !== null) {
            return $contextCenterId;
        }

        $centerSlug = trim((string) ($parameters['centrumSlug'] ?? ''));
        if ($centerSlug === '') {
            return '';
        }

        $center = $this->resolveCenterBySlug($allCenters, $centerSlug);

        return $center !== null ? trim((string) ($center['id'] ?? '')) : '';
    }

    private function resolveCenterBySlug(array $centers, string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        foreach ($centers as $center) {
            if ($this->buildCenterSlug($center) !== $slug) {
                continue;
            }

            return $center;
        }

        return null;
    }

    private function buildCenterSlug(array $center): string
    {
        $id = trim((string) ($center['id'] ?? ''));
        $name = trim((string) ($center['name'] ?? ''));
        $city = trim((string) ($center['city'] ?? ''));

        $parts = array_filter([$name, $city], static fn (string $value): bool => $value !== '');
        $base = $this->slugify(implode('-', $parts));

        if ($base === '') {
            $base = 'centrum';
        }

        if ($id === '') {
            return $base;
        }

        return $base . '-' . $id;
    }

    private function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $map = [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
        ];
        $value = strtr($value, $map);
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';

        return trim($value, '-');
    }

    /**
     * @param array<int, array<string, mixed>> $centers
     *
     * @return array<string, mixed>|null
     */
    private function resolveCenterById(array $centers, string $centerId): ?array
    {
        if ($centerId === '') {
            return null;
        }

        foreach ($centers as $center) {
            if (trim((string) ($center['id'] ?? '')) !== $centerId) {
                continue;
            }

            return $center;
        }

        return null;
    }


    /**
     * @param array<int, array<string, mixed>> $entries
     *
     * @return array<string, mixed>|null
     */
    private function resolveRulesContent(array $entries): ?array
    {
        $prettyFilesById = $this->buildPrettyFilesById();
        $prettyFileDataById = $this->buildPrettyFileDataById();

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            for ($step = 1; $step <= 4; $step++) {
                unset($entry['tytul_' . $step]);
                $rulesTextField = 'opis_loteria_' . $step;
                if (array_key_exists($rulesTextField, $entry)) {
                    $entry[$rulesTextField] = $this->decodeHtmlEntities((string) ($entry[$rulesTextField] ?? ''));
                }
            }

            foreach (['informacje'] as $rulesInfoField) {
                if (array_key_exists($rulesInfoField, $entry)) {
                    $entry[$rulesInfoField] = $this->decodeHtmlEntities((string) ($entry[$rulesInfoField] ?? ''));
                }
            }

            foreach (['formularz_tekst_dol'] as $formBottomTextField) {
                if (array_key_exists($formBottomTextField, $entry)) {
                    $entry[$formBottomTextField] = $this->decodeHtmlEntities((string) ($entry[$formBottomTextField] ?? ''));
                }
            }

            foreach (['formularz_regulamin_plik'] as $rulesFileField) {
                $rulesFileId = trim((string) ($entry[$rulesFileField] ?? ''));
                $entry[$rulesFileField] = $rulesFileId !== '' ? ($prettyFilesById[$rulesFileId] ?? '') : '';
            }

            foreach (['produkty', 'nagrody'] as $imageListField) {
                $imageList = $entry[$imageListField] ?? null;
                $imageItems = [];
                if (is_array($imageList)) {
                    $imageItems = $imageList;
                } elseif (is_string($imageList) && $imageList !== '') {
                    $decoded = json_decode($imageList, true);
                    if (is_array($decoded)) {
                        $imageItems = $decoded;
                    }
                }

                $entry[$imageListField] = [];
                foreach ($imageItems as $imageItem) {
                    if (!is_array($imageItem)) {
                        continue;
                    }

                    $imageUrl = trim((string) ($imageItem['zdjecie'] ?? ''));
                    if ($imageUrl === '') {
                        continue;
                    }

                    $entry[$imageListField][] = [
                        'zdjecie' => $imageUrl,
                    ];
                }
            }

            foreach (['stopka_linki'] as $footerLinksField) {
                $footerLinks = $entry[$footerLinksField] ?? null;
                $footerLinkItems = [];

                if (is_array($footerLinks)) {
                    $footerLinkItems = $footerLinks;
                } elseif (is_string($footerLinks) && $footerLinks !== '') {
                    $decoded = json_decode($footerLinks, true);
                    if (is_array($decoded)) {
                        $footerLinkItems = $decoded;
                    }
                }

                $entry[$footerLinksField] = [];
                foreach ($footerLinkItems as $footerLinkItem) {
                    if (is_array($footerLinkItem)) {
                        $fileId = trim((string) ($footerLinkItem['file_id'] ?? ''));
                        $linkName = trim((string) ($footerLinkItem['nazwa'] ?? ''));
                        if ($fileId === '' || $linkName === '' || !isset($prettyFileDataById[$fileId])) {
                            continue;
                        }

                        $entry[$footerLinksField][] = [
                            'nazwa' => $linkName,
                            'link' => $prettyFileDataById[$fileId]['link'],
                        ];
                        continue;
                    }

                    $fileId = trim((string) $footerLinkItem);
                    if ($fileId === '' || !isset($prettyFileDataById[$fileId])) {
                        continue;
                    }

                    $entry[$footerLinksField][] = $prettyFileDataById[$fileId];
                }
            }

            return $entry;
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     *
     * @return array<string, mixed>|null
     */
    private function resolveSellerPageContent(array $entries): ?array
    {
        $prettyFilesById = $this->buildPrettyFilesById();

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            for ($step = 1; $step <= 4; $step++) {
                $contentField = 'tresc_' . $step;
                if (array_key_exists($contentField, $entry)) {
                    $entry[$contentField] = $this->decodeHtmlEntities((string) ($entry[$contentField] ?? ''));
                }
            }

            if (array_key_exists('nagrody_tekst', $entry)) {
                $entry['nagrody_tekst'] = $this->decodeHtmlEntities((string) ($entry['nagrody_tekst'] ?? ''));
            }

            if (array_key_exists('formularz_tekst_dol', $entry)) {
                $entry['formularz_tekst_dol'] = $this->decodeHtmlEntities((string) ($entry['formularz_tekst_dol'] ?? ''));
            }

            $rulesFileId = trim((string) ($entry['plik_z_regulaminem'] ?? ''));
            $entry['plik_z_regulaminem'] = $rulesFileId !== '' ? ($prettyFilesById[$rulesFileId] ?? '') : '';

            $lotteryRulesFileId = trim((string) ($entry['regulamin_loterii'] ?? ''));
            $entry['regulamin_loterii'] = $lotteryRulesFileId !== '' ? ($prettyFilesById[$lotteryRulesFileId] ?? '') : '';

            return $entry;
        }

        return null;
    }

    private function decodeHtmlEntities(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $decoded = $value;
        for ($iteration = 0; $iteration < 5; $iteration++) {
            $previous = $decoded;
            $decoded = html_entity_decode($previous, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $previous) {
                break;
            }
        }

        return $decoded;
    }

    private function buildPrettyFilesById(): array
    {
        $entries = $this->fetchModuleEntries('pliki');
        $seenSlugs = [];
        $filesById = [];

        foreach ($entries as $entry) {
            $fileId = trim((string) ($entry['id'] ?? ''));
            $relativePath = trim((string) ($entry['plik'] ?? ''));
            if ($fileId === '' || $relativePath === '') {
                continue;
            }

            $slug = $this->buildPrettyFileSlug($entry, $seenSlugs);
            $filesById[$fileId] = '/pliki/' . $slug;
        }

        return $filesById;
    }


    /**
     * @return array<string, array{nazwa: string, link: string}>
     */
    private function buildPrettyFileDataById(): array
    {
        $entries = $this->fetchModuleEntries('pliki');
        $seenSlugs = [];
        $filesById = [];

        foreach ($entries as $entry) {
            $fileId = trim((string) ($entry['id'] ?? ''));
            $relativePath = trim((string) ($entry['plik'] ?? ''));
            if ($fileId === '' || $relativePath === '') {
                continue;
            }

            $slug = $this->buildPrettyFileSlug($entry, $seenSlugs);
            $filesById[$fileId] = [
                'nazwa' => trim((string) ($entry['nazwa'] ?? '')),
                'link' => '/pliki/' . $slug,
            ];
        }

        return $filesById;
    }

    /**
     * @return array<string, string>
     */
    private function buildPrettyFilesMap(): array
    {
        $entries = $this->fetchModuleEntries('pliki');
        $seenSlugs = [];
        $filesBySlug = [];

        foreach ($entries as $entry) {
            $relativePath = trim((string) ($entry['plik'] ?? ''));
            if ($relativePath === '') {
                continue;
            }

            $slug = $this->buildPrettyFileSlug($entry, $seenSlugs);
            $filesBySlug[$slug] = $relativePath;
        }

        return $filesBySlug;
    }

    /**
     * @param array<string, int> $seenSlugs
     */
    private function buildPrettyFileSlug(array $fileEntry, array &$seenSlugs): string
    {
        $relativePath = trim((string) ($fileEntry['plik'] ?? ''));
        $path = (string) parse_url($relativePath, PHP_URL_PATH);
        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $nameFromCms = trim((string) ($fileEntry['nazwa'] ?? ''));
        $fallbackName = pathinfo($path, PATHINFO_FILENAME);
        $baseName = $this->slugify($nameFromCms !== '' ? $nameFromCms : (string) $fallbackName);
        if ($baseName === '') {
            $baseName = 'plik';
        }

        $candidate = $baseName;
        if ($extension !== '') {
            $candidate .= '.' . $extension;
        }

        $occurrences = ($seenSlugs[$candidate] ?? 0) + 1;
        $seenSlugs[$candidate] = $occurrences;
        if ($occurrences <= 1) {
            return $candidate;
        }

        if ($extension === '') {
            return sprintf('%s-%d', $baseName, $occurrences);
        }

        return sprintf('%s-%d.%s', $baseName, $occurrences, $extension);
    }

    private function resolvePublicFileAbsolutePath(string $relativePath): ?string
    {
        return $this->resolveStoredFileAbsolutePath($relativePath);
    }

    private function resolveStoredFileAbsolutePath(string $relativePath): ?string
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '') {
            return null;
        }

        $rootDir = dirname(__DIR__, 2);
        if (str_starts_with($relativePath, '/uploads/')) {
            $absolutePath = $rootDir . '/public_html' . $relativePath;

            return is_file($absolutePath) ? $absolutePath : null;
        }

        if (preg_match('#^/media/modules/([A-Za-z0-9_-]+)/([^/]+)$#', $relativePath, $matches) === 1) {
            $moduleKey = $matches[1];
            $filename = rawurldecode($matches[2]);
            if ($filename === '' || str_contains($filename, '..') || str_contains($filename, '\\')) {
                return null;
            }

            $storagePath = $rootDir . '/var/uploads/modules/' . $moduleKey . '/' . $filename;
            if (is_file($storagePath)) {
                return $storagePath;
            }

            $legacyPath = $rootDir . '/public_html/uploads/modules/' . $moduleKey . '/' . $filename;

            return is_file($legacyPath) ? $legacyPath : null;
        }

        if (preg_match('#^/media/winner-receipts/([^/]+)$#', $relativePath, $matches) === 1) {
            return $this->resolveWinnerReceiptAbsolutePath($matches[1]);
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $lotteries
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterLotteriesByCenter(array $lotteries, string $centerId): array
    {
        if ($centerId === '') {
            return $lotteries;
        }

        $filteredLotteries = array_values(array_filter($lotteries, function (array $lottery) use ($centerId): bool {
            $centerIds = $this->decodeRelationIds($lottery['centra_handlowe'] ?? null);

            return in_array($centerId, $centerIds, true);
        }));

        return $filteredLotteries !== [] ? $filteredLotteries : $lotteries;
    }

    /**
     * @return array<int, string>
     */
    private function decodeRelationIds(mixed $rawValue): array
    {
        if (is_array($rawValue)) {
            $decoded = $rawValue;
        } elseif (is_string($rawValue) && $rawValue !== '') {
            $decoded = json_decode($rawValue, true);
            if (!is_array($decoded)) {
                return [];
            }
        } else {
            return [];
        }

        $ids = [];
        foreach ($decoded as $value) {
            $id = trim((string) $value);
            if ($id === '') {
                continue;
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    public function submitNewsletter(Request $request): Response
    {
        $data = $request->request->all();
        $errors = [];
        $addError = static function (string $field, string $message) use (&$errors): void {
            $errors[] = $message;
        };
        $allCenters = $this->fetchActiveShoppingCenters();
        $selectedCenterId = $this->resolveSelectedCenterIdFromRequest($request, $allCenters, [], $data);
        $selectedCenter = $this->resolveCenterById($allCenters, $selectedCenterId);
        if ($selectedCenter === null) {
            $selectedCenterId = '';
        }

        if ($selectedCenterId === '' && $allCenters !== []) {
            $selectedCenterId = trim((string) ($allCenters[0]['id'] ?? ''));
            $selectedCenter = $this->resolveCenterById($allCenters, $selectedCenterId);
        }
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));

        if ($email === '') {
            $errors[] = 'Adres e-mail jest wymagany.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $addError('email', 'Adres e-mail ma nieprawidłowy format.');
        } elseif (($suggestedDomain = $this->resolveSuggestedEmailDomain($email)) !== null && !$this->isSuggestedEmailDomainConfirmed($data, $email)) {
            $this->rememberSuggestedEmailDomainWarning($email);
            $data['email_domain_warning_ack'] = $email;
            $addError('email', sprintf('Sprawdź domenę adresu e-mail. Czy chodziło o %s? Jeśli adres jest poprawny, wyślij formularz ponownie.', $suggestedDomain));
        }

        $definition = $this->resolveModuleDefinition('newsletter');
        $table = $definition['database']['table'] ?? null;

        $newsletterLotteries = [];
        $newsletterLotteries = $this->filterLotteriesByCenter($newsletterLotteries, $selectedCenterId);
        $currentNewsletterLottery = $this->resolveCurrentLottery($this->fetchModuleEntry('loteria_ustawienia') ?? []);
        if (!$currentNewsletterLottery) {
            $errors[] = 'Brak aktywnej loterii newsletterowej.';
        }

        if ($table) {
            $connection = $this->entityManager->getConnection();
            $existing = $connection->createQueryBuilder()
                ->select('id', 'confirmed', 'confirmation_token', 'confirmation_sent_at')
                ->from($table)
                ->where('email = :email')
                ->setParameter('email', $email)
                ->executeQuery()
                ->fetchAssociative();

            if ($existing) {
                if (!empty($existing['confirmed'])) {
                    $errors[] = 'Podany adres e-mail jest już zapisany do newslettera.';
                } else {
                    $submittedAt = new \DateTimeImmutable();
                    $storedToken = (string) ($existing['confirmation_token'] ?? '');
                    $token = $storedToken !== '' ? $storedToken : bin2hex(random_bytes(16));
                    $confirmationLink = rtrim($request->getSchemeAndHttpHost(), '/')
                        . '/newsletter/confirm/' . urlencode($token);
                    $shouldSend = true;
                    if (!empty($existing['confirmation_sent_at'])) {
                        $lastSentAt = new \DateTimeImmutable((string) $existing['confirmation_sent_at']);
                        $shouldSend = $lastSentAt->modify('+5 minutes') <= $submittedAt;
                    }
                    $updateData = [];
                    if ($token !== $storedToken) {
                        $updateData['confirmation_token'] = $token;
                    }
                    if ($shouldSend) {
                        $updateData['confirmation_sent_at'] = $submittedAt->format('Y-m-d H:i:s');
                    }
                    if ($updateData !== []) {
                        $connection->update($table, $updateData, ['id' => $existing['id']]);
                    }
                    if ($shouldSend) {
                        $this->sendNewsletterConfirmationEmail($email, $confirmationLink);
                    }

                    $_SESSION['newsletter_submission'] = ['email' => $email];

                    return new RedirectResponse('/loteria');
                }
            }
        } else {
            $errors[] = 'Brak konfiguracji modułu newslettera.';
        }

        if ($errors !== []) {
            $settings = $this->fetchModuleEntry('settings') ?? [];

            return $this->render($this->resolveRegistrationPageTemplate($settings), [
                'settings' => $settings,
                'centers' => $allCenters,
                'centerUrls' => [],
                'selectedCenterId' => $selectedCenterId,
                'selectedCenter' => $selectedCenter,
                'sellerCenterUrl' => $this->buildSellerRegistrationUrl($selectedCenterId, $allCenters),
                'submission' => null,
                'lotterySubmission' => null,
                'currentNewsletterLottery' => $currentNewsletterLottery,
                'newsletterSubmission' => null,
                'newsletterConfirmation' => null,
                'newsletterErrors' => $errors,
                'newsletterFormData' => $data,
                'lotteryFormToken' => $this->issueLotteryFormToken(),
            ]);
        }

        $submittedAt = new \DateTimeImmutable();
        $token = bin2hex(random_bytes(16));
        $confirmationLink = rtrim($request->getSchemeAndHttpHost(), '/')
            . '/newsletter/confirm/' . urlencode($token);

        $connection = $this->entityManager->getConnection();
        $connection->insert($table, [
            'email' => $email,
            'confirmed' => false,
            'confirmation_token' => $token,
            'confirmation_sent_at' => $submittedAt->format('Y-m-d H:i:s'),
            'confirmed_at' => null,
            'created_at' => $submittedAt->format('Y-m-d H:i:s'),
            'ip_address' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'accept_language' => $request->headers->get('Accept-Language'),
            'referer' => $request->headers->get('Referer'),
        ]);

        $this->sendNewsletterConfirmationEmail($email, $confirmationLink);

        $_SESSION['newsletter_submission'] = ['email' => $email];

        return new RedirectResponse('/loteria');
    }

    public function confirmNewsletter(Request $request, array $parameters): Response
    {
        $token = trim((string) ($parameters['token'] ?? ''));
        $definition = $this->resolveModuleDefinition('newsletter');
        $table = $definition['database']['table'] ?? null;
        $status = 'invalid';

        if ($token !== '' && $table) {
            $connection = $this->entityManager->getConnection();
            $entry = $connection->createQueryBuilder()
                ->select('*')
                ->from($table)
                ->where('confirmation_token = :token')
                ->setParameter('token', $token)
                ->executeQuery()
                ->fetchAssociative();

            if ($entry) {
                if (!empty($entry['confirmed'])) {
                    $status = 'already_confirmed';
                } else {
                    $status = 'confirmed';
                    $connection->update($table, [
                        'confirmed' => true,
                        'confirmed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                        'confirmation_token' => null,
                    ], [
                        'id' => $entry['id'],
                    ]);
                }
            }
        }

        $_SESSION['newsletter_confirmation'] = ['status' => $status];

        return new RedirectResponse('/loteria');
    }

    
    /**
     * @param array<string, mixed> $data
     */
    private function sendLotterySubmissionEmail(
        string $recipient,
        array $data,
        \DateTimeImmutable $submittedAt,
        string $scratchLink,
        int $ticketCountForSubmission,
        int $totalTicketCount,
        int $scratchcardCountForSubmission = 0
    ): void
    {
        $recipient = trim($recipient);
        if ($recipient === '') {
            return;
        }

        $settings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
        $templateId = $settings['submission_email_template'] ?? null;
        if (!is_string($templateId) && !is_int($templateId)) {
            return;
        }

        $definition = $this->resolveModuleDefinition('email_templates');
        $table = $definition['database']['table'] ?? null;
        if (!is_string($table) || $table === '') {
            return;
        }

        $connection = $this->entityManager->getConnection();
        $template = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where('id = :id')
            ->setParameter('id', (string) $templateId)
            ->executeQuery()
            ->fetchAssociative();

        if (!$template) {
            return;
        }

        $placeholders = [
            '{data}',
            '{data_czas}',
            '{email}',
            '{telefon}',
            '{link_zdrapki}',
            '{liczba-losow-za-zgloszenie}',
            '{liczba-zdrapek-za-zgloszenie}',
            '{laczna-liczba-wszytskich-losow}',
        ];
        $replacement = [
            $submittedAt->format('d-m-Y'),
            $submittedAt->format('Y-m-d H:i:s'),
            $recipient,
            trim((string) ($data['phone'] ?? '')),
            $scratchLink,
            (string) max(0, $ticketCountForSubmission),
            (string) max(0, $scratchcardCountForSubmission),
            (string) max(0, $totalTicketCount),
        ];

        $subject = str_replace($placeholders, $replacement, (string) ($template['subject'] ?? ''));
        $body = str_replace($placeholders, $replacement, (string) ($template['body'] ?? ''));

        $this->mailer->send($recipient, $subject, $body, true, $this->resolveTemplateBcc($template), $this->isPriorityEmailTemplate($template));
    }

    /**
     * @param array<string, mixed> $submission
     */
    private function sendLotteryReminderEmail(array $submission, string $scratchLink, \DateTimeImmutable $sentAt): bool
    {
        $recipient = trim((string) ($submission['email'] ?? ''));
        if ($recipient === '') {
            return false;
        }

        $settings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
        $templateId = $settings['reminder_email_template'] ?? null;
        if (!is_string($templateId) && !is_int($templateId)) {
            return false;
        }

        $definition = $this->resolveModuleDefinition('email_templates');
        $table = $definition['database']['table'] ?? null;
        if (!is_string($table) || $table === '') {
            return false;
        }

        $connection = $this->entityManager->getConnection();
        $template = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where('id = :id')
            ->setParameter('id', (string) $templateId)
            ->executeQuery()
            ->fetchAssociative();

        if (!$template) {
            return false;
        }

        $placeholders = ['{data}', '{data_czas}', '{email}', '{telefon}', '{link_zdrapki}'];
        $replacement = [
            $sentAt->format('d-m-Y'),
            $sentAt->format('Y-m-d H:i:s'),
            $recipient,
            trim((string) ($submission['phone'] ?? '')),
            $scratchLink,
        ];

        $subject = str_replace($placeholders, $replacement, (string) ($template['subject'] ?? ''));
        $body = str_replace($placeholders, $replacement, (string) ($template['body'] ?? ''));

        $this->mailer->send($recipient, $subject, $body, true, $this->resolveTemplateBcc($template), $this->isPriorityEmailTemplate($template));

        return true;
    }

    /**
     * @param array<string, mixed> $ticket
     * @return array{success: bool, error?: string|null, queued?: bool, template_id?: string, template_name?: string}
     */
    private function sendLotteryWinnerEmail(array $ticket, ?string $prizeName, ?string $voucherCode, \DateTimeImmutable $checkedAt, string $winnerLink, int $prizeTypeId): array
    {
        $recipient = trim((string) ($ticket['email'] ?? ''));
        $submissionId = (int) ($ticket['zgloszenie'] ?? 0);
        $purchaseDocumentNumber = '';
        $submissionPhone = '';

        $connection = $this->entityManager->getConnection();
        if ($submissionId > 0) {
            $submissionDefinition = $this->resolveModuleDefinition('loteria_zgloszenia');
            $submissionTable = $submissionDefinition['database']['table'] ?? null;
            if (is_string($submissionTable) && $submissionTable !== '') {
                $submission = $connection->createQueryBuilder()
                    ->select('email', 'phone', 'purchase_document_number')
                    ->from($submissionTable)
                    ->where('id = :id')
                    ->setParameter('id', $submissionId)
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchAssociative();

                if (is_array($submission)) {
                    if ($recipient === '') {
                        $recipient = trim((string) ($submission['email'] ?? ''));
                    }
                    $submissionPhone = trim((string) ($submission['phone'] ?? ''));
                    $purchaseDocumentNumber = trim((string) ($submission['purchase_document_number'] ?? ''));
                }
            }
        }

        if ($recipient === '') {
            return ['success' => false, 'error' => 'Brak adresu e-mail zwycięzcy.'];
        }
        if ($prizeTypeId <= 0) {
            return ['success' => false, 'error' => 'Brak przypisanego rodzaju nagrody dla wygranej e-zdrapki.'];
        }

        $prizeTypeDefinition = $this->resolveModuleDefinition('rodzaje_nagrod');
        $prizeTypeTable = $prizeTypeDefinition['database']['table'] ?? null;
        if (!is_string($prizeTypeTable) || $prizeTypeTable === '') {
            return ['success' => false, 'error' => 'Brak konfiguracji modułu rodzajów nagród.'];
        }

        $prizeType = $connection->createQueryBuilder()
            ->select('prize_info_email_template', 'wartosc_nagrody')
            ->from($prizeTypeTable)
            ->where('id = :id')
            ->setParameter('id', $prizeTypeId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($prizeType) || $prizeType === []) {
            return ['success' => false, 'error' => 'Nie znaleziono wskazanego rodzaju nagrody.'];
        }

        $templateId = $prizeType['prize_info_email_template'] ?? '';
        $templateId = is_int($templateId) ? (string) $templateId : trim((string) $templateId);
        $prizeValue = trim((string) ($prizeType['wartosc_nagrody'] ?? ''));
        if ($templateId === '') {
            return ['success' => false, 'error' => 'Brak szablonu w polu "Mail z informacją o wygranej i formularzu" dla rodzaju nagrody.'];
        }

        $definition = $this->resolveModuleDefinition('email_templates');
        $table = $definition['database']['table'] ?? null;
        if (!is_string($table) || $table === '') {
            return ['success' => false, 'error' => 'Brak konfiguracji modułu szablonów e-mail.'];
        }

        $template = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where('id = :id')
            ->setParameter('id', $templateId)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($template) || $template === []) {
            return ['success' => false, 'error' => 'Nie znaleziono szablonu e-maila dla wygranej e-zdrapki.'];
        }

        $placeholders = ['{data}', '{data_czas}', '{email}', '{telefon}', '{los}', '{dowod_zakupu}', '{nagroda}', '{wartosc-nagrody}', '{kod_vouchera}', '{link_zwyciezca}', '{data_losowania}', '{termin_formularza}'];
        $replacement = [
            $checkedAt->format('d-m-Y'),
            $checkedAt->format('Y-m-d H:i:s'),
            $recipient,
            $submissionPhone !== '' ? $submissionPhone : trim((string) ($ticket['phone'] ?? '')),
            trim((string) ($ticket['ticket_id'] ?? '')),
            $purchaseDocumentNumber,
            $prizeName ?? '',
            $prizeValue,
            $voucherCode ?? '',
            $winnerLink,
            $this->resolveWinnerDrawingDatePlaceholder($ticket),
            $this->resolveWinnerFormDeadlinePlaceholder($ticket, $checkedAt),
        ];

        $subject = str_replace($placeholders, $replacement, (string) ($template['subject'] ?? ''));
        $body = str_replace($placeholders, $replacement, (string) ($template['body'] ?? ''));

        $sendResult = $this->mailer->sendWithError($recipient, $subject, $body, true, $this->resolveTemplateBcc($template), false, $this->isPriorityEmailTemplate($template));
        if ($sendResult['success'] ?? false) {
            $sendResult['template_id'] = $templateId;
            $sendResult['template_name'] = trim((string) ($template['name'] ?? ''));
        }

        return $sendResult;
    }

    private function buildScratchcardsLink(Request $request, string $token): string
    {
        $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');

        return $baseUrl . '/loteria/zdrapki/' . urlencode($token);
    }

    private function buildWinnerLink(Request $request, string $token): string
    {
        $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');

        return $baseUrl . '/loteria/zwyciezca/' . urlencode($token) . '#zglos';
    }

    private function generateScratchcardsToken(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $exception) {
            return sha1(uniqid((string) mt_rand(), true));
        }
    }

    private function resolveScratchcardsTokenForEmail(Connection $connection, string $submissionTable, string $email): string
    {
        $normalizedEmail = mb_strtolower(trim($email));
        if ($normalizedEmail !== '') {
            $existingToken = $connection->createQueryBuilder()
                ->select('scratch_link_token')
                ->from($submissionTable)
                ->where('LOWER(TRIM(email)) = :email')
                ->andWhere('scratch_link_token IS NOT NULL')
                ->andWhere('scratch_link_token <> :emptyToken')
                ->setParameter('email', $normalizedEmail)
                ->setParameter('emptyToken', '')
                ->orderBy('id', 'ASC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
            $existingToken = trim((string) $existingToken);
            if ($existingToken !== '') {
                return $existingToken;
            }
        }

        return $this->generateScratchcardsToken();
    }

    /**
     * @return array{email: bool, phone: bool}
     */
    private function findLotteryEmailPhoneIdentityConflicts(string $submissionTable, string $email, string $phone): array
    {
        $connection = $this->entityManager->getConnection();

        $emailConflict = ((int) $connection->createQueryBuilder()
            ->select('COUNT(id)')
            ->from($submissionTable)
            ->where('LOWER(TRIM(email)) = :email')
            ->andWhere('phone IS NOT NULL')
            ->andWhere("TRIM(phone) <> ''")
            ->andWhere('TRIM(phone) <> :phone')
            ->setParameter('email', $email)
            ->setParameter('phone', $phone)
            ->executeQuery()
            ->fetchOne()) > 0;

        $phoneConflict = ((int) $connection->createQueryBuilder()
            ->select('COUNT(id)')
            ->from($submissionTable)
            ->where('TRIM(phone) = :phone')
            ->andWhere('email IS NOT NULL')
            ->andWhere("TRIM(email) <> ''")
            ->andWhere('LOWER(TRIM(email)) <> :email')
            ->setParameter('phone', $phone)
            ->setParameter('email', $email)
            ->executeQuery()
            ->fetchOne()) > 0;

        return [
            'email' => $emailConflict,
            'phone' => $phoneConflict,
        ];
    }

    private function hasLotterySubmissionForEmailToday(string $submissionTable, string $email): bool
    {
        $dayStart = new \DateTimeImmutable('today');
        $nextDayStart = $dayStart->modify('+1 day');

        return ((int) $this->entityManager->getConnection()->createQueryBuilder()
            ->select('COUNT(id)')
            ->from($submissionTable)
            ->where('LOWER(TRIM(email)) = :email')
            ->andWhere('submitted_at >= :dayStart')
            ->andWhere('submitted_at < :nextDayStart')
            ->setParameter('email', $email)
            ->setParameter('dayStart', $dayStart->format('Y-m-d H:i:s'))
            ->setParameter('nextDayStart', $nextDayStart->format('Y-m-d H:i:s'))
            ->executeQuery()
            ->fetchOne()) > 0;
    }

    /**
     * @param string[] $lockNames
     * @return string[]|null
     */
    private function acquireLotterySubmissionLocks(array $lockNames): ?array
    {
        $connection = $this->entityManager->getConnection();
        $platformName = strtolower($connection->getDatabasePlatform()->getName());
        if (!str_contains($platformName, 'mysql') && !str_contains($platformName, 'mariadb')) {
            return [];
        }

        $lockNames = array_values(array_unique($lockNames));
        sort($lockNames, SORT_STRING);
        $acquiredLockNames = [];

        foreach ($lockNames as $lockName) {
            $acquired = (int) $connection->fetchOne('SELECT GET_LOCK(?, 5)', [$lockName]);
            if ($acquired === 1) {
                $acquiredLockNames[] = $lockName;
                continue;
            }

            $this->releaseLotterySubmissionLocks($acquiredLockNames);

            return null;
        }

        return $acquiredLockNames;
    }

    /**
     * @param string[] $lockNames
     */
    private function releaseLotterySubmissionLocks(array $lockNames): void
    {
        if ($lockNames === []) {
            return;
        }

        $connection = $this->entityManager->getConnection();
        $platformName = strtolower($connection->getDatabasePlatform()->getName());
        if (!str_contains($platformName, 'mysql') && !str_contains($platformName, 'mariadb')) {
            return;
        }

        foreach ($lockNames as $lockName) {
            try {
                $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]);
            } catch (\Throwable $exception) {
                // A lost database connection releases its advisory locks automatically.
            }
        }
    }

    private function isLotteryReceiptUploadAllowed(UploadedFile $uploadedFile): bool
    {
        if ($uploadedFile->getSize() <= 0 || $uploadedFile->getSize() > LotteryRegistrationValidator::MAX_RECEIPT_SIZE) {
            return false;
        }

        $mimeType = strtolower((string) $uploadedFile->getMimeType());
        $extension = strtolower((string) $uploadedFile->getClientOriginalExtension());
        $allowedExtensions = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'application/pdf' => ['pdf'],
        ];

        return isset($allowedExtensions[$mimeType])
            && in_array($extension, $allowedExtensions[$mimeType], true);
    }

    private function discardLotteryReceipt(?string $path): void
    {
        if ($path !== null && str_starts_with($path, '/media/modules/loteria_zgloszenia/')) {
            $localPath = dirname(__DIR__, 2) . '/var/uploads/modules/loteria_zgloszenia/' . basename($path);
            if (is_file($localPath)) {
                @unlink($localPath);
            }
        }
    }

    private function storeLotteryReceipt(UploadedFile $uploadedFile, string $moduleKey): ?string
    {
        $rootDir = dirname(__DIR__, 2);
        $uploadDir = $rootDir . '/var/uploads/modules/' . $moduleKey;
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return null;
        }

        $extension = strtolower((string) $uploadedFile->getClientOriginalExtension());
        $filename = bin2hex(random_bytes(16));
        if ($extension !== '') {
            $filename .= '.' . $extension;
        }

        try {
            $uploadedFile->move($uploadDir, $filename);
        } catch (\Throwable $exception) {
            return null;
        }

        return '/media/modules/' . rawurlencode($moduleKey) . '/' . rawurlencode($filename);
    }

    private function isImageUploadAllowed(UploadedFile $uploadedFile): bool
    {
        $mimeType = $uploadedFile->getMimeType();
        if (!is_string($mimeType) || !str_starts_with($mimeType, 'image/')) {
            return false;
        }

        $extension = strtolower((string) $uploadedFile->getClientOriginalExtension());
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        return $extension === '' || in_array($extension, $allowedExtensions, true);
    }

    private function storeWinnerReceipt(UploadedFile $uploadedFile): ?string
    {
        $rootDir = dirname(__DIR__, 2);
        $uploadDir = $rootDir . '/var/uploads/winner-receipts';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return null;
        }

        $extension = strtolower((string) $uploadedFile->getClientOriginalExtension());
        $filename = bin2hex(random_bytes(16));
        if ($extension !== '') {
            $filename .= '.' . $extension;
        }

        try {
            $uploadedFile->move($uploadDir, $filename);
        } catch (\Throwable $exception) {
            return null;
        }

        return '/media/winner-receipts/' . rawurlencode($filename);
    }

    private function resolveWinnerReceiptAbsolutePath(string $filename): ?string
    {
        $decodedFilename = rawurldecode(trim($filename));
        if ($decodedFilename === '' || str_contains($decodedFilename, '..') || str_contains($decodedFilename, '/')
            || str_contains($decodedFilename, '\\')) {
            return null;
        }

        $rootDir = dirname(__DIR__, 2);
        $storagePath = $rootDir . '/var/uploads/winner-receipts/' . $decodedFilename;
        if (is_file($storagePath)) {
            return $storagePath;
        }

        $legacyPath = $rootDir . '/public_html/uploads/modules/zwyciezcy/' . $decodedFilename;
        if (is_file($legacyPath)) {
            return $legacyPath;
        }

        return null;
    }

    /**
     * @return array<string, array<int, array{id: string, name: string}>>
     */
    private function resolveSellerStoreOptionsByCenter(): array
    {
        $storeOptionsByCenter = [];
        $stores = $this->fetchModuleEntries('lista_sklepow', ['active' => 1]);

        foreach ($stores as $store) {
            $centerId = trim((string) ($store['centrum_handlowe'] ?? ''));
            $storeId = trim((string) ($store['id'] ?? ''));
            if ($centerId === '' || $storeId === '') {
                continue;
            }

            $storeOptionsByCenter[$centerId][] = [
                'id' => $storeId,
                'name' => trim((string) ($store['nazwa_sklepu'] ?? '')),
            ];
        }

        foreach ($storeOptionsByCenter as $centerId => $centerStores) {
            usort($centerStores, static function (array $left, array $right): int {
                return strcmp(mb_strtolower($left['name']), mb_strtolower($right['name']));
            });
            $storeOptionsByCenter[$centerId] = $centerStores;
        }

        return $storeOptionsByCenter;
    }


    private function sendSellerAccountConfirmedEmail(string $recipient, string $sellerCode = '', string $sellerPageLink = ''): void
    {
        $recipient = trim($recipient);
        $sellerCode = trim($sellerCode);
        $sellerPageLink = trim($sellerPageLink);
        if ($recipient === '') {
            return;
        }

        $settings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
        $templateId = $settings['seller_account_confirmed_email_template'] ?? null;
        $subject = 'Konto sprzedawcy zostało potwierdzone';
        $body = '<p>Twoje konto sprzedawcy zostało pomyślnie potwierdzone.</p>';

        if (is_string($templateId) || is_int($templateId)) {
            $definition = $this->resolveModuleDefinition('email_templates');
            $table = $definition['database']['table'] ?? null;

            if (is_string($table) && $table !== '') {
                $connection = $this->entityManager->getConnection();
                $template = $connection->createQueryBuilder()
                    ->select('*')
                    ->from($table)
                    ->where('id = :id')
                    ->setParameter('id', (string) $templateId)
                    ->executeQuery()
                    ->fetchAssociative();

                if ($template) {
                    $placeholders = ['{email}', '{kod}', '{link_sprzedawcy}'];
                    $replacement = [$recipient, $sellerCode, $sellerPageLink];
                    $subject = str_replace($placeholders, $replacement, (string) ($template['subject'] ?? $subject));
                    $body = str_replace($placeholders, $replacement, (string) ($template['body'] ?? $body));
                }
            }
        }

        $this->mailer->send($recipient, $subject, $body, true, $this->resolveTemplateBcc($template), $this->isPriorityEmailTemplate($template));
    }

    private function sendSellerRegistrationConfirmationEmail(string $recipient, string $confirmationLink, string $sellerPageLink = ''): void
    {
        $recipient = trim($recipient);
        $sellerPageLink = trim($sellerPageLink);
        if ($recipient === '') {
            return;
        }

        $settings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
        $templateId = $settings['seller_registration_email_template'] ?? null;
        $subject = 'Potwierdzenie rejestracji sprzedawcy';
        $body = sprintf(
            '<p>Dziękujemy za rejestrację w formularzu M1 LP Sprzedawców.</p><p>Potwierdź swój adres e-mail klikając w link: <a href="%1$s">%1$s</a></p>',
            $confirmationLink
        );

        if (is_string($templateId) || is_int($templateId)) {
            $definition = $this->resolveModuleDefinition('email_templates');
            $table = $definition['database']['table'] ?? null;

            if (is_string($table) && $table !== '') {
                $connection = $this->entityManager->getConnection();
                $template = $connection->createQueryBuilder()
                    ->select('*')
                    ->from($table)
                    ->where('id = :id')
                    ->setParameter('id', (string) $templateId)
                    ->executeQuery()
                    ->fetchAssociative();

                if ($template) {
                    $placeholders = ['{link}', '{email}', '{link_sprzedawcy}'];
                    $replacement = [$confirmationLink, $recipient, $sellerPageLink];
                    $subject = str_replace($placeholders, $replacement, (string) ($template['subject'] ?? $subject));
                    $body = str_replace($placeholders, $replacement, (string) ($template['body'] ?? $body));
                }
            }
        }

        $this->mailer->send($recipient, $subject, $body, true, $this->resolveTemplateBcc($template), $this->isPriorityEmailTemplate($template));
    }

    private function sendNewsletterConfirmationEmail(string $recipient, string $confirmationLink): void
    {
        $recipient = trim($recipient);
        if ($recipient === '') {
            return;
        }

        $settings = $this->fetchModuleEntry('settings') ?? [];
        $templateId = $settings['newsletter_confirmation_email_template'] ?? null;
        $subject = 'Potwierdzenie zapisu do newslettera';
        $body = sprintf(
            '<p>Dziękujemy za zapis do newslettera.</p><p>Potwierdź zapis klikając w link: <a href="%1$s">%1$s</a></p>',
            $confirmationLink
        );

        if (is_string($templateId) || is_int($templateId)) {
            $definition = $this->resolveModuleDefinition('email_templates');
            $table = $definition['database']['table'] ?? null;

            if (is_string($table) && $table !== '') {
                $connection = $this->entityManager->getConnection();
                $template = $connection->createQueryBuilder()
                    ->select('*')
                    ->from($table)
                    ->where('id = :id')
                    ->setParameter('id', (string) $templateId)
                    ->executeQuery()
                    ->fetchAssociative();

                if ($template) {
                    $placeholders = ['{link}', '{email}'];
                    $replacement = [$confirmationLink, $recipient];
                    $subject = str_replace($placeholders, $replacement, (string) ($template['subject'] ?? $subject));
                    $body = str_replace($placeholders, $replacement, (string) ($template['body'] ?? $body));
                }
            }
        }

        $this->mailer->send($recipient, $subject, $body, true, $this->resolveTemplateBcc($template), $this->isPriorityEmailTemplate($template));
    }

    private function sendReceiptSentEmail(string $recipient, string $phone, string $purchaseDocumentNumber): void
    {
        $recipient = trim($recipient);
        if ($recipient === '') {
            return;
        }

        $settings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
        $templateId = $settings['receipt_sent_email_template'] ?? null;
        if (!is_string($templateId) && !is_int($templateId)) {
            return;
        }

        $definition = $this->resolveModuleDefinition('email_templates');
        $table = $definition['database']['table'] ?? null;
        if (!is_string($table) || $table === '') {
            return;
        }

        $connection = $this->entityManager->getConnection();
        $template = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where('id = :id')
            ->setParameter('id', (string) $templateId)
            ->executeQuery()
            ->fetchAssociative();

        if (!$template) {
            return;
        }

        $sentAt = new \DateTimeImmutable();
        $placeholders = [
            '{data}',
            '{data_czas}',
            '{email}',
            '{telefon}',
            '{dowod_zakupu}',
        ];
        $replacement = [
            $sentAt->format('d-m-Y'),
            $sentAt->format('Y-m-d H:i:s'),
            $recipient,
            trim($phone),
            trim($purchaseDocumentNumber),
        ];

        $subject = str_replace($placeholders, $replacement, (string) ($template['subject'] ?? ''));
        $body = str_replace($placeholders, $replacement, (string) ($template['body'] ?? ''));

        $this->mailer->send($recipient, $subject, $body, true, $this->resolveTemplateBcc($template), $this->isPriorityEmailTemplate($template));
    }

    /**
     * @param array<string, mixed> $data
     */
    /**
     * @param array<string, mixed> $data
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function formatMaskedValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $start = mb_substr($value, 0, 2);
        $end = mb_substr($value, -2);
        if ($start === '' || $end === '' || mb_strlen($value) < 3) {
            return $value;
        }

        return $start . '...' . $end;
    }

    private function formatCodeSpoken(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '';
        }

        $phonetic = [
            'A' => 'Alfa',
            'B' => 'Bravo',
            'C' => 'Charlie',
            'D' => 'Delta',
            'E' => 'Echo',
            'F' => 'Foxtrot',
            'G' => 'Golf',
            'H' => 'Hotel',
            'I' => 'India',
            'J' => 'Juliett',
            'K' => 'Kilo',
            'L' => 'Lima',
            'M' => 'Mike',
            'N' => 'November',
            'O' => 'Oscar',
            'P' => 'Papa',
            'Q' => 'Quebec',
            'R' => 'Romeo',
            'S' => 'Sierra',
            'T' => 'Tango',
            'U' => 'Uniform',
            'V' => 'Victor',
            'W' => 'Whiskey',
            'X' => 'X-ray',
            'Y' => 'Yankee',
            'Z' => 'Zulu',
            '0' => 'Zero',
            '1' => 'Jeden',
            '2' => 'Dwa',
            '3' => 'Trzy',
            '4' => 'Cztery',
            '5' => 'Pięć',
            '6' => 'Sześć',
            '7' => 'Siedem',
            '8' => 'Osiem',
            '9' => 'Dziewięć',
        ];

        $parts = [];
        foreach (preg_split('//u', $code, -1, PREG_SPLIT_NO_EMPTY) as $character) {
            $upper = mb_strtoupper($character);
            $parts[] = $phonetic[$upper] ?? $character;
        }

        return implode(', ', $parts);
    }

    private function generateUniqueSellerCode(Connection $connection, string $table): string
    {
        $alphabet = str_split('ACDEFHJKMNPQRTUVWXY346789');

        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, count($alphabet) - 1)];
            }

            $existingCode = $connection->createQueryBuilder()
                ->select('id')
                ->from($table)
                ->where('kod_sprzedawcy = :sellerCode')
                ->setParameter('sellerCode', $code)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
        } while ($existingCode !== false);

        return $code;
    }

    private function isEmailAssignedToConfirmedSellerAccount(string $email): bool
    {
        $normalizedEmail = mb_strtolower(trim($email));
        if ($normalizedEmail === '') {
            return false;
        }

        $definition = $this->resolveModuleDefinition('sprzedawcy');
        $table = $definition['database']['table'] ?? null;
        if (!is_string($table) || $table === '') {
            return false;
        }

        $existingSellerId = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('id')
            ->from($table)
            ->where('LOWER(email) = :email')
            ->andWhere('confirmed = :confirmed')
            ->setParameter('email', $normalizedEmail)
            ->setParameter('confirmed', true)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $existingSellerId !== false;
    }

    private function validateRecaptcha(?string $responseToken, ?string $remoteIp): ?string
    {
        $settingsProvider = new SystemSettingsProvider($this->entityManager);
        $recaptchaConfig = $settingsProvider->getRecaptchaConfig();

        if (!$recaptchaConfig['enabled']) {
            return null;
        }

        if ($recaptchaConfig['site_key'] === '') {
            return 'Brak konfiguracji reCAPTCHA w ustawieniach systemu.';
        }

        $token = trim((string) $responseToken);
        if ($token === '') {
            return 'Potwierdź, że nie jesteś robotem.';
        }

        $httpClient = HttpClient::create();
        try {
            if ($recaptchaConfig['use_enterprise']) {
                if ($recaptchaConfig['enterprise_project_id'] === '' || $recaptchaConfig['enterprise_api_key'] === '') {
                    return 'Brak konfiguracji reCAPTCHA Enterprise w ustawieniach systemu.';
                }

                $response = $httpClient->request('POST', sprintf(
                    'https://recaptchaenterprise.googleapis.com/v1/projects/%s/assessments?key=%s',
                    $recaptchaConfig['enterprise_project_id'],
                    $recaptchaConfig['enterprise_api_key']
                ), [
                    'json' => [
                        'event' => [
                            'token' => $token,
                            'siteKey' => $recaptchaConfig['site_key'],
                            'expectedAction' => 'submit',
                            'userIpAddress' => $remoteIp,
                        ],
                    ],
                ]);
                $payload = $response->toArray(false);
            } else {
                if ($recaptchaConfig['secret_key'] === '') {
                    return 'Brak konfiguracji reCAPTCHA w ustawieniach systemu.';
                }

                $response = $httpClient->request('POST', 'https://www.google.com/recaptcha/api/siteverify', [
                    'body' => [
                        'secret' => $recaptchaConfig['secret_key'],
                        'response' => $token,
                        'remoteip' => $remoteIp,
                    ],
                ]);
                $payload = $response->toArray(false);
            }
        } catch (TransportExceptionInterface $exception) {
            return 'Nie udało się zweryfikować reCAPTCHA. Spróbuj ponownie.';
        }

        if ($recaptchaConfig['use_enterprise']) {
            $tokenProperties = $payload['tokenProperties'] ?? [];
            if (!($tokenProperties['valid'] ?? false)) {
                return 'Weryfikacja reCAPTCHA nie powiodła się. Spróbuj ponownie.';
            }
            if (($tokenProperties['action'] ?? '') !== 'submit') {
                return 'Weryfikacja reCAPTCHA nie powiodła się. Spróbuj ponownie.';
            }
            $assessmentReason = $tokenProperties['invalidReason'] ?? null;
            if (is_string($assessmentReason) && $assessmentReason !== '') {
                return 'Weryfikacja reCAPTCHA nie powiodła się. Spróbuj ponownie.';
            }
        } else {
            if (!($payload['success'] ?? false)) {
                return 'Weryfikacja reCAPTCHA nie powiodła się. Spróbuj ponownie.';
            }

            if (isset($payload['action']) && $payload['action'] !== 'submit') {
                return 'Weryfikacja reCAPTCHA nie powiodła się. Spróbuj ponownie.';
            }
        }

        return null;
    }
    /**
     * @param array<string, mixed> $template
     */
    protected function isPriorityEmailTemplate(array $template): bool
    {
        return filter_var($template['priority_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }


}
