<?php

namespace App\Controller;

use App\Entity\ModuleChangeLog;
use App\Service\PermissionService;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ReceiptVerificationController extends BaseController
{
    private const LIST_STATE_SESSION_PREFIX = 'receipt_verification_list_state_';
    private const SOURCE_STATUS = 'ai_sprawdzone_niepoprawnie';
    private const IN_VERIFICATION_STATUS = 'do_weryfikacji';

    public function index(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('zwyciezcy', PermissionService::ACTION_EDIT)) {
            return $response;
        }

        $this->hydrateListStateFromSession($request, 'verification');

        $winnerId = (int) $request->query->get('winner_id', 0);
        $winner = $winnerId > 0
            ? $this->fetchWinnerByIdAndStatus($winnerId, self::SOURCE_STATUS)
            : null;
        $winners = $this->fetchWinnersByStatus(self::SOURCE_STATUS);
        foreach ($winners as &$listWinner) {
            $listWinner['centerName'] = $this->resolveCenterName($listWinner);
            $listWinner['centerAddress'] = $this->resolveCenterAddress($listWinner);
            $listWinner['storeDisplayName'] = $this->resolveStoreDisplayName($listWinner);
            $listWinner['submissionEmail'] = $this->resolveSubmissionEmail($listWinner);
            $listWinner['automaticVerificationStatus'] = $this->resolveAutomaticVerificationStatus($listWinner);
            $listWinner['storeFilterKey'] = $this->buildStoreFilterKey($listWinner);
            $listWinner['storeFilterLabel'] = $this->buildStoreFilterLabel($listWinner);
        }
        unset($listWinner);
        $listFilters = $this->resolveListFilters($winners, $request);
        $listQueryParams = $this->extractListQueryParams($request->query);
        $this->storeListState($request, 'verification', $listQueryParams);
        $winners = $listFilters['winners'];
        $filteredCount = count($winners);

        if ($winner) {
            $winner['submissionEmail'] = $this->resolveSubmissionEmail($winner);
            $winner['sellerCode'] = $this->resolveSubmissionSellerCode($winner);
            $winner['ticketCountForSubmission'] = $this->resolveTicketCountForSubmission($winner);
            $winner['automaticVerificationStatus'] = $this->resolveAutomaticVerificationStatus($winner);
            $winner['centerName'] = $this->resolveCenterName($winner);
            $winner['centerAddress'] = $this->resolveCenterAddress($winner);
            $winner['storeName'] = $this->resolveStoreName($winner);
            $winner['storeDisplayName'] = $this->resolveStoreDisplayName($winner);
            $winner['otherWinningScratchcardNotice'] = $this->resolveOtherWinningScratchcardNotice($winner);
        }

        $pendingCount = $this->countReceiptsByStatus(self::SOURCE_STATUS);

        return $this->render('backend/receipt_verification.html.twig', [
            'user' => $this->authService->user(),
            'winner' => $winner,
            'winners' => $winners,
            'alert' => $this->resolveAlert($request),
            'pendingCount' => $pendingCount,
            'filteredCount' => $filteredCount,
            'listFilters' => $listFilters,
            'listQueryParams' => $listQueryParams,
        ]);
    }

    public function inVerification(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('zwyciezcy', PermissionService::ACTION_EDIT)) {
            return $response;
        }

        $this->hydrateListStateFromSession($request, 'in_verification');

        $winnerId = (int) $request->query->get('winner_id', 0);
        $winner = $winnerId > 0
            ? $this->fetchWinnerByIdAndStatus($winnerId, self::IN_VERIFICATION_STATUS)
            : null;
        $winners = $this->fetchWinnersByStatus(self::IN_VERIFICATION_STATUS);
        $pendingCount = count($winners);
        foreach ($winners as &$listWinner) {
            $listWinner['centerName'] = $this->resolveCenterName($listWinner);
            $listWinner['centerAddress'] = $this->resolveCenterAddress($listWinner);
            $listWinner['storeDisplayName'] = $this->resolveStoreDisplayName($listWinner);
            $listWinner['submissionEmail'] = $this->resolveSubmissionEmail($listWinner);
            $listWinner['automaticVerificationStatus'] = $this->resolveAutomaticVerificationStatus($listWinner);
            $listWinner['storeFilterKey'] = $this->buildStoreFilterKey($listWinner);
            $listWinner['storeFilterLabel'] = $this->buildStoreFilterLabel($listWinner);
        }
        unset($listWinner);
        $listFilters = $this->resolveListFilters($winners, $request);
        $listQueryParams = $this->extractListQueryParams($request->query);
        $this->storeListState($request, 'in_verification', $listQueryParams);
        $winners = $listFilters['winners'];
        $filteredCount = count($winners);

        if ($winner) {
            $winner['submissionEmail'] = $this->resolveSubmissionEmail($winner);
            $winner['sellerCode'] = $this->resolveSubmissionSellerCode($winner);
            $winner['ticketCountForSubmission'] = $this->resolveTicketCountForSubmission($winner);
            $winner['automaticVerificationStatus'] = $this->resolveAutomaticVerificationStatus($winner);
            $winner['centerName'] = $this->resolveCenterName($winner);
            $winner['centerAddress'] = $this->resolveCenterAddress($winner);
            $winner['storeName'] = $this->resolveStoreName($winner);
            $winner['storeDisplayName'] = $this->resolveStoreDisplayName($winner);
            $winner['otherWinningScratchcardNotice'] = $this->resolveOtherWinningScratchcardNotice($winner);
        }

        return $this->render('backend/receipt_in_verification.html.twig', [
            'user' => $this->authService->user(),
            'winner' => $winner,
            'winners' => $winners,
            'alert' => $this->resolveAlert($request),
            'pendingCount' => $pendingCount,
            'filteredCount' => $filteredCount,
            'listFilters' => $listFilters,
            'listQueryParams' => $listQueryParams,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $winners
     * @return array{
     *   winners: array<int, array<string, mixed>>,
     *   selected: array{center: string, store: string, aiVerification: string, sort: string, direction: string},
     *   options: array{
     *      centers: array<int, array{value: string, label: string}>,
     *      stores: array<int, array{value: string, label: string}>
     *   }
     * }
     */
    private function resolveListFilters(array $winners, Request $request): array
    {
        $selectedCenter = trim((string) $request->query->get('centrum_handlowe', ''));
        $selectedStore = trim((string) $request->query->get('sklep', ''));
        $selectedAiVerification = trim((string) $request->query->get('weryfikacja_ai', ''));
        $selectedSort = trim((string) $request->query->get('sort', ''));
        $selectedDirection = strtolower(trim((string) $request->query->get('direction', 'asc')));
        $allowedAiVerificationValues = ['Pozytywnie', 'Negatywnie'];
        $allowedSortValues = ['id', 'center', 'store', 'email', 'filledDate', 'aiVerification', 'submissionDate'];
        if (!in_array($selectedAiVerification, $allowedAiVerificationValues, true)) {
            $selectedAiVerification = '';
        }
        if (!in_array($selectedSort, $allowedSortValues, true)) {
            $selectedSort = '';
        }
        if (!in_array($selectedDirection, ['asc', 'desc'], true)) {
            $selectedDirection = 'asc';
        }

        $filteredWinners = array_values(array_filter(
            $winners,
            static function (array $winner) use ($selectedCenter, $selectedStore, $selectedAiVerification): bool {
                if ($selectedCenter !== '' && (string) ($winner['centrum_handlowe'] ?? '') !== $selectedCenter) {
                    return false;
                }

                if ($selectedStore !== '' && (string) ($winner['storeFilterKey'] ?? '') !== $selectedStore) {
                    return false;
                }

                if ($selectedAiVerification !== '' && (string) ($winner['automaticVerificationStatus'] ?? '') !== $selectedAiVerification) {
                    return false;
                }

                return true;
            }
        ));
        $this->sortWinners($filteredWinners, $selectedSort, $selectedDirection);

        return [
            'winners' => $filteredWinners,
            'selected' => [
                'center' => $selectedCenter,
                'store' => $selectedStore,
                'aiVerification' => $selectedAiVerification,
                'sort' => $selectedSort,
                'direction' => $selectedDirection,
            ],
            'options' => [
                'centers' => $this->buildCenterFilterOptions($filteredWinners),
                'stores' => $this->buildStoreFilterOptions($filteredWinners),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $winners
     */
    private function sortWinners(array &$winners, string $sort, string $direction): void
    {
        if ($sort === '') {
            return;
        }

        usort(
            $winners,
            static function (array $left, array $right) use ($sort, $direction): int {
                $multiplier = $direction === 'desc' ? -1 : 1;
                $leftValue = self::resolveSortValue($left, $sort);
                $rightValue = self::resolveSortValue($right, $sort);
                if ($leftValue === $rightValue) {
                    return 0;
                }

                if (is_int($leftValue) && is_int($rightValue)) {
                    return ($leftValue <=> $rightValue) * $multiplier;
                }

                return strcasecmp((string) $leftValue, (string) $rightValue) * $multiplier;
            }
        );
    }

    /**
     * @param array<string, mixed> $winner
     * @return int|string
     */
    private static function resolveSortValue(array $winner, string $sort)
    {
        if ($sort === 'id') {
            return (int) ($winner['id'] ?? 0);
        }

        if ($sort === 'center') {
            return trim((string) ($winner['centerName'] ?? ''));
        }

        if ($sort === 'store') {
            return trim((string) ($winner['storeDisplayName'] ?? ''));
        }

        if ($sort === 'email') {
            return trim((string) ($winner['submissionEmail'] ?? ''));
        }

        if ($sort === 'filledDate') {
            return trim((string) ($winner['zgloszenie_wypelnione_at'] ?? ''));
        }

        if ($sort === 'aiVerification') {
            return trim((string) ($winner['automaticVerificationStatus'] ?? ''));
        }

        if ($sort === 'submissionDate') {
            return trim((string) ($winner['email_o_wygranej_wyslany_at'] ?? ''));
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $winners
     * @return array<int, array{value: string, label: string}>
     */
    private function buildCenterFilterOptions(array $winners): array
    {
        $options = [];
        foreach ($winners as $winner) {
            $centerId = trim((string) ($winner['centrum_handlowe'] ?? ''));
            if ($centerId === '' || $centerId === '0') {
                continue;
            }

            $centerName = trim((string) ($winner['centerName'] ?? ''));
            if ($centerName === '') {
                continue;
            }

            $options[$centerId] = [
                'value' => $centerId,
                'label' => $centerName,
            ];
        }

        uasort($options, static fn (array $left, array $right): int => strcasecmp($left['label'], $right['label']));

        return array_values($options);
    }

    /**
     * @param array<int, array<string, mixed>> $winners
     * @return array<int, array{value: string, label: string}>
     */
    private function buildStoreFilterOptions(array $winners): array
    {
        $options = [];
        foreach ($winners as $winner) {
            $storeFilterKey = trim((string) ($winner['storeFilterKey'] ?? ''));
            $storeFilterLabel = trim((string) ($winner['storeFilterLabel'] ?? ''));
            if ($storeFilterKey === '' || $storeFilterLabel === '') {
                continue;
            }

            $options[$storeFilterKey] = [
                'value' => $storeFilterKey,
                'label' => $storeFilterLabel,
            ];
        }

        uasort($options, static fn (array $left, array $right): int => strcasecmp($left['label'], $right['label']));

        return array_values($options);
    }

    /**
     * @param array<string, mixed>|null $winner
     */
    private function buildStoreFilterKey(?array $winner): string
    {
        if (!is_array($winner)) {
            return '';
        }

        $centerId = trim((string) ($winner['centrum_handlowe'] ?? '0'));
        $storeId = trim((string) ($winner['sklep'] ?? '0'));
        $storeName = trim((string) ($winner['storeDisplayName'] ?? ''));

        return sprintf('%s|%s|%s', $centerId, $storeId, mb_strtolower($storeName));
    }

    /**
     * @param array<string, mixed>|null $winner
     */
    private function buildStoreFilterLabel(?array $winner): string
    {
        if (!is_array($winner)) {
            return '';
        }

        $storeName = trim((string) ($winner['storeDisplayName'] ?? ''));
        $centerName = trim((string) ($winner['centerName'] ?? ''));
        if ($storeName === '') {
            return '';
        }

        if ($centerName === '') {
            return $storeName;
        }

        return sprintf('%s (%s)', $storeName, $centerName);
    }

    public function update(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('zwyciezcy', PermissionService::ACTION_EDIT)) {
            return $response;
        }

        $winnerId = (int) $request->request->get('winner_id', 0);
        if ($winnerId <= 0) {
            return new RedirectResponse('/cms/sprawdz-paragony' . $this->buildRedirectQueryString($request, 'error', 'Nie wybrano zgłoszenia do sprawdzenia.'));
        }

        $winner = $this->fetchWinnerByIdAndStatus($winnerId, self::SOURCE_STATUS);
        if (!$winner) {
            return new RedirectResponse('/cms/sprawdz-paragony' . $this->buildRedirectQueryString($request, 'error', 'Wybrane zgłoszenie nie jest już dostępne do sprawdzenia.'));
        }

        $decision = strtolower((string) $request->request->get('decision', ''));
        $statusByDecision = [
            'valid' => 'sprawdzone',
            'invalid' => 'odrzucone',
            'verify' => self::IN_VERIFICATION_STATUS,
        ];

        if (!isset($statusByDecision[$decision])) {
            return new RedirectResponse('/cms/sprawdz-paragony?winner_id=' . $winnerId . '&status=error&message=' . rawurlencode('Nie wybrano poprawnej decyzji'));
        }

        $this->saveWinnerDecision($winner, $statusByDecision[$decision], $request, $decision);

        return new RedirectResponse('/cms/sprawdz-paragony' . $this->buildRedirectQueryString($request, 'success', 'Decyzja została zapisana.'));
    }

    public function updateInVerification(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('zwyciezcy', PermissionService::ACTION_EDIT)) {
            return $response;
        }

        $winnerId = (int) $request->request->get('winner_id', 0);
        if ($winnerId <= 0) {
            return new RedirectResponse('/cms/w-weryfikacji' . $this->buildRedirectQueryString($request, 'error', 'Nie wybrano zgłoszenia.'));
        }

        $winner = $this->fetchWinnerById($winnerId);
        if (!$winner || strtolower((string) ($winner['stan'] ?? '')) !== self::IN_VERIFICATION_STATUS) {
            return new RedirectResponse('/cms/w-weryfikacji' . $this->buildRedirectQueryString($request, 'error', 'Wybrane zgłoszenie nie jest już dostępne do aktualizacji.'));
        }

        $decision = strtolower((string) $request->request->get('decision', ''));
        $statusByDecision = [
            'valid' => 'sprawdzone',
            'invalid' => 'odrzucone',
            'update' => self::IN_VERIFICATION_STATUS,
        ];

        if (!isset($statusByDecision[$decision])) {
            return new RedirectResponse('/cms/w-weryfikacji?winner_id=' . $winnerId . '&status=error&message=' . rawurlencode('Nie wybrano poprawnej decyzji'));
        }

        $this->saveWinnerDecision($winner, $statusByDecision[$decision], $request, $decision);

        return new RedirectResponse('/cms/w-weryfikacji' . $this->buildRedirectQueryString($request, 'success', 'Dane zgłoszenia zostały zapisane.'));
    }

    /**
     * @return array{centrum_handlowe?: string, sklep?: string, weryfikacja_ai?: string, sort?: string, direction?: string}
     */
    private function extractListQueryParams(ParameterBag $bag): array
    {
        $queryParams = [];
        foreach (['centrum_handlowe', 'sklep', 'weryfikacja_ai', 'sort', 'direction'] as $key) {
            $value = trim((string) $bag->get($key, ''));
            if ($value !== '') {
                $queryParams[$key] = $value;
            }
        }

        return $queryParams;
    }

    private function buildRedirectQueryString(Request $request, string $status, string $message): string
    {
        $queryParams = $this->extractListQueryParams($request->request);
        $queryParams['status'] = $status;
        $queryParams['message'] = $message;

        return '?' . http_build_query($queryParams);
    }

    /**
     * @param array<string, scalar|null> $state
     */
    private function storeListState(Request $request, string $listKey, array $state): void
    {
        if ($listKey === '' || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $sessionKey = self::LIST_STATE_SESSION_PREFIX . $listKey;

        if ($state === []) {
            $session->remove($sessionKey);

            return;
        }

        $session->set($sessionKey, $state);
    }

    private function hydrateListStateFromSession(Request $request, string $listKey): void
    {
        if ($listKey === '' || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $sessionKey = self::LIST_STATE_SESSION_PREFIX . $listKey;

        if ($request->query->getBoolean('reset', false)) {
            $session->remove($sessionKey);
            $request->query->remove('reset');

            return;
        }

        $query = $request->query->all();
        $alertQuery = [];
        foreach (['status', 'message'] as $key) {
            if (!array_key_exists($key, $query) || !is_scalar($query[$key])) {
                continue;
            }

            $alertQuery[$key] = (string) $query[$key];
            unset($query[$key]);
        }

        if ($query !== []) {
            return;
        }

        $state = $session->get($sessionKey, []);
        if (!is_array($state) || $state === []) {
            return;
        }

        $normalizedState = [];
        foreach ($state as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            $normalizedState[$key] = (string) $value;
        }

        if ($normalizedState === [] && $alertQuery === []) {
            return;
        }

        $request->query->replace(array_merge($normalizedState, $alertQuery));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchNextWinnerForVerification(): ?array
    {
        $winner = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('*')
            ->from('loteria_zwyciezcy_entries')
            ->where('stan = :status')
            ->setParameter('status', self::SOURCE_STATUS)
            ->orderBy('id', 'asc')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($winner) ? $winner : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchWinnersByStatus(string $status): array
    {
        $rows = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('*')
            ->from('loteria_zwyciezcy_entries')
            ->where('stan = :status')
            ->setParameter('status', $status)
            ->orderBy('id', 'asc')
            ->executeQuery()
            ->fetchAllAssociative();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchWinnerById(int $winnerId): ?array
    {
        $winner = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('*')
            ->from('loteria_zwyciezcy_entries')
            ->where('id = :id')
            ->setParameter('id', $winnerId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($winner) ? $winner : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchWinnerByIdAndStatus(int $winnerId, string $status): ?array
    {
        $winner = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('*')
            ->from('loteria_zwyciezcy_entries')
            ->where('id = :id')
            ->andWhere('stan = :status')
            ->setParameter('id', $winnerId)
            ->setParameter('status', $status)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($winner) ? $winner : null;
    }

    private function countReceiptsByStatus(string $status): int
    {
        $count = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('loteria_zwyciezcy_entries')
            ->where('stan = :status')
            ->setParameter('status', $status)
            ->executeQuery()
            ->fetchOne();

        return max(0, (int) $count);
    }

    /**
     * @param array<string, mixed>|null $winner
     */
    private function resolveCenterName(?array $winner): ?string
    {
        return $this->resolveNameById($winner['centrum_handlowe'] ?? null, 'centra_handlowe_entries', 'name');
    }

    /**
     * @param array<string, mixed>|null $winner
     */
    private function resolveCenterAddress(?array $winner): ?string
    {
        return $this->resolveNameById($winner['centrum_handlowe'] ?? null, 'centra_handlowe_entries', 'adres');
    }

    /**
     * @param array<string, mixed>|null $winner
     */
    private function resolveStoreName(?array $winner): ?string
    {
        return $this->resolveNameById($winner['sklep'] ?? null, 'lista_sklepow_entries', 'nazwa_sklepu');
    }

    /**
     * @param array<string, mixed>|null $winner
     */
    private function resolveStoreDisplayName(?array $winner): string
    {
        $storeName = trim((string) ($this->resolveStoreName($winner) ?? ''));
        if ($storeName !== '' && $storeName !== '-') {
            return $storeName;
        }

        $submission = $this->fetchSubmissionForWinner($winner);
        $submissionStore = trim((string) ($submission['sklep'] ?? ''));
        if ($submissionStore !== '' && $submissionStore !== '-') {
            return $this->resolveNameById($submissionStore, 'lista_sklepow_entries', 'nazwa_sklepu') ?? $submissionStore;
        }

        return 'KARTOMAT';
    }

    /**
     * @param array<string, mixed>|null $winner
     */
    private function resolveSubmissionEmail(?array $winner): ?string
    {
        $submission = $this->fetchSubmissionForWinner($winner);
        $email = trim((string) ($submission['email'] ?? ($winner['email'] ?? '')));

        return $email !== '' ? $email : null;
    }

    /**
     * @param array<string, mixed>|null $winner
     */
    private function resolveSubmissionSellerCode(?array $winner): ?string
    {
        $submission = $this->fetchSubmissionForWinner($winner);
        $sellerCode = trim((string) ($submission['seller_code'] ?? ''));

        return $sellerCode !== '' ? $sellerCode : null;
    }

    /**
     * @param array<string, mixed>|null $winner
     * @return array<string, mixed>|null
     */
    private function fetchSubmissionForWinner(?array $winner): ?array
    {
        if (!is_array($winner)) {
            return null;
        }

        $submissionId = (int) ($winner['zgloszenie'] ?? 0);
        if ($submissionId <= 0) {
            return null;
        }

        $submission = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('*')
            ->from('loteria_zgloszenia_entries')
            ->where('id = :id')
            ->setParameter('id', $submissionId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($submission) ? $submission : null;
    }

    /**
     * @param int|string|null $id
     */
    private function resolveNameById($id, string $table, string $column): ?string
    {
        $numericId = (int) $id;
        if ($numericId <= 0) {
            return null;
        }

        $value = $this->entityManager->getConnection()->createQueryBuilder()
            ->select($column)
            ->from($table)
            ->where('id = :id')
            ->setParameter('id', $numericId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{type: string, message: string}|null
     */
    private function resolveAlert(Request $request): ?array
    {
        $status = strtolower((string) $request->query->get('status', ''));
        $message = trim((string) $request->query->get('message', ''));

        if ($message === '' || !in_array($status, ['success', 'error'], true)) {
            return null;
        }

        return [
            'type' => $status === 'success' ? 'success' : 'danger',
            'message' => $message,
        ];
    }

    private function resolveUserComment(Request $request): ?string
    {
        $comment = trim((string) $request->request->get('komentarz_uzytkownika', ''));

        return $comment === '' ? null : $comment;
    }

    /**
     * @param array<string, mixed>|null $winner
     */
    private function resolveTicketCountForSubmission(?array $winner): int
    {
        if (!is_array($winner)) {
            return 0;
        }

        $submissionId = (int) ($winner['zgloszenie'] ?? 0);
        if ($submissionId <= 0) {
            return 0;
        }

        $count = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('loteria_losy_entries')
            ->where('zgloszenie = :submissionId')
            ->setParameter('submissionId', $submissionId)
            ->executeQuery()
            ->fetchOne();

        return max(0, (int) $count);
    }

    /**
     * @param array<string, mixed>|null $winner
     */
    private function resolveAutomaticVerificationStatus(?array $winner): string
    {
        if (!is_array($winner)) {
            return 'Brak danych';
        }

        $aiResponse = trim((string) ($winner['odpowiedz_ai'] ?? ''));
        if ($aiResponse !== '') {
            $decodedResponse = json_decode($aiResponse, true);
            if (is_array($decodedResponse) && array_key_exists('is_valid', $decodedResponse)) {
                $isValid = $this->normalizeAiBoolean($decodedResponse['is_valid']);
                if ($isValid === null) {
                    return 'Brak danych';
                }

                return $isValid ? 'Pozytywnie' : 'Negatywnie';
            }

            return $aiResponse;
        }

        $status = strtolower(trim((string) ($winner['stan'] ?? '')));
        if ($status === 'ai_sprawdzone') {
            return 'Pozytywnie';
        }

        if (in_array($status, ['ai_sprawdzone_niepoprawnie', self::IN_VERIFICATION_STATUS], true)) {
            return 'Negatywnie';
        }

        return 'Brak danych';
    }

    /**
     * @param mixed $value
     */
    private function normalizeAiBoolean($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            if ($value === 1) {
                return true;
            }

            if ($value === 0) {
                return false;
            }
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'tak'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'nie'], true)) {
                return false;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $winner
     */
    private function saveWinnerDecision(array $winner, string $status, Request $request, string $decision): void
    {
        $comment = $this->resolveUserComment($request);
        $this->entityManager->getConnection()->update(
            'loteria_zwyciezcy_entries',
            [
                'stan' => $status,
                'komentarz_uzytkownika' => $comment,
            ],
            ['id' => $winner['id'] ?? 0]
        );

        $this->recordReceiptVerificationChange($winner, $status, $comment, $decision, $request);

        try {
            if ($decision === 'valid') {
                $this->sendWinnerCodeEmail($winner, $request);
            } elseif ($decision === 'invalid') {
                $this->sendPrizeRejectionEmail($winner, $request);
            }
        } catch (\Throwable $exception) {
            // Błędy wysyłki e-maila nie mogą blokować zapisu decyzji.
        }
    }


    /**
     * @param array<string, mixed> $winner
     */
    private function recordReceiptVerificationChange(array $winner, string $newStatus, ?string $newComment, string $decision, Request $request): void
    {
        $winnerId = (int) ($winner['id'] ?? 0);
        if ($winnerId <= 0) {
            return;
        }

        $previousStatus = trim((string) ($winner['stan'] ?? ''));
        $previousCommentRaw = $winner['komentarz_uzytkownika'] ?? null;
        $previousComment = is_string($previousCommentRaw) ? trim($previousCommentRaw) : null;
        if ($previousComment === '') {
            $previousComment = null;
        }

        $changeSet = [
            [
                'field' => 'stan',
                'label' => 'Status',
                'from' => $previousStatus,
                'to' => $newStatus,
            ],
            [
                'field' => 'komentarz_uzytkownika',
                'label' => 'Komentarz użytkownika',
                'from' => $previousComment,
                'to' => $newComment,
            ],
        ];

        $actionMap = [
            'valid' => 'receipt_verification_valid',
            'invalid' => 'receipt_verification_invalid',
            'verify' => 'receipt_verification_to_verify',
            'update' => 'receipt_verification_update',
        ];
        $action = $actionMap[$decision] ?? 'receipt_verification_update';

        $user = $this->authService->user();
        $log = new ModuleChangeLog();
        $log->setModuleKey('zwyciezcy');
        $log->setAction($action);
        $log->setRecordId($winnerId);
        $log->setUser($user);
        $log->setEmail($user?->getEmail() ?? '');
        $log->setIpAddress($request->getClientIp());
        $log->setUserAgent($request->headers->get('User-Agent'));
        $log->setChangedAt(new \DateTimeImmutable());
        $log->setChangeSet(json_encode($changeSet, JSON_UNESCAPED_UNICODE));

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed>|null $winner
     */
    private function resolveOtherWinningScratchcardNotice(?array $winner): ?string
    {
        if (!is_array($winner)) {
            return null;
        }

        $winnerId = (int) ($winner['id'] ?? 0);
        $submissionId = (int) ($winner['zgloszenie'] ?? 0);
        $currentScratchcardId = (int) ($winner['zdrapka_w_loterii'] ?? 0);

        if ($winnerId <= 0 || $submissionId <= 0) {
            return null;
        }

        $queryBuilder = $this->entityManager->getConnection()->createQueryBuilder();
        $queryBuilder
            ->select('z.id AS scratchcard_id', 'w.id AS related_winner_id', 'w.stan AS related_winner_status')
            ->from('loteria_zdrapki_entries', 'z')
            ->leftJoin(
                'z',
                'loteria_zwyciezcy_entries',
                'w',
                'w.zdrapka_w_loterii = z.id AND w.rodzaj = :kind AND w.id <> :winnerId'
            )
            ->where('z.zgloszenie = :submissionId')
            ->andWhere('z.wygrany = :isWinning')
            ->setParameter('kind', 'zdrapka')
            ->setParameter('winnerId', $winnerId)
            ->setParameter('submissionId', $submissionId)
            ->setParameter('isWinning', '1')
            ->orderBy('z.id', 'asc')
            ->setMaxResults(1);

        if ($currentScratchcardId > 0) {
            $queryBuilder
                ->andWhere('z.id <> :currentScratchcardId')
                ->setParameter('currentScratchcardId', $currentScratchcardId);
        }

        $otherWinningScratchcard = $queryBuilder->executeQuery()->fetchAssociative();
        if (!is_array($otherWinningScratchcard)) {
            return null;
        }

        $relatedWinnerId = (int) ($otherWinningScratchcard['related_winner_id'] ?? 0);
        $relatedWinnerStatus = (string) ($otherWinningScratchcard['related_winner_status'] ?? '');
        $normalizedStatus = strtolower($relatedWinnerStatus);

        if ($relatedWinnerId > 0 && in_array($normalizedStatus, ['sprawdzone', 'odrzucone', 'do_weryfikacji'], true)) {
            return sprintf(
                'Uwaga: inna e-zdrapka z tego zgłoszenia (ID zdrapki: %d, ID zwycięzcy: %d) ma już status: %s.',
                (int) $otherWinningScratchcard['scratchcard_id'],
                $relatedWinnerId,
                $relatedWinnerStatus
            );
        }

        if ($relatedWinnerId > 0) {
            return sprintf(
                'Uwaga: inna e-zdrapka z tego zgłoszenia (ID zdrapki: %d, ID zwycięzcy: %d) istnieje, ale jej status nie jest oznaczony jako poprawny/niepoprawny/do weryfikacji.',
                (int) $otherWinningScratchcard['scratchcard_id'],
                $relatedWinnerId
            );
        }

        return sprintf(
            'Uwaga: inna e-zdrapka z tego zgłoszenia (ID zdrapki: %d) istnieje, ale nie ma jeszcze rekordu w module Zwycięzcy.',
            (int) $otherWinningScratchcard['scratchcard_id']
        );
    }
}
