<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class EmailTemplatesModuleListActionController extends ModuleListActionController
{
    /** @var \Closure(): ?User */
    private \Closure $resolveUser;
    /** @var \Closure(Request, string, string, string): RedirectResponse */
    private \Closure $buildModuleAlertRedirect;
    /** @var \Closure(Request, string, string, ?int): void */
    private \Closure $recordModuleChange;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly \App\Service\Mailer $mailer,
        \Closure $resolveUser,
        \Closure $buildModuleAlertRedirect,
        \Closure $recordModuleChange,
    ) {
        $this->resolveUser = $resolveUser;
        $this->buildModuleAlertRedirect = $buildModuleAlertRedirect;
        $this->recordModuleChange = $recordModuleChange;
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    protected function sendTestEmailTemplate(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        $user = ($this->resolveUser)();
        if (!$user || $user->getEmail() === '') {
            return ($this->buildModuleAlertRedirect)($request, $moduleKey, 'error', 'Brak adresu e-mail zalogowanego użytkownika.');
        }

        $database = $definition['database'];
        $table = $database['table'] ?? null;
        $primaryKey = $database['primary_key'] ?? 'id';

        if (!$table) {
            return ($this->buildModuleAlertRedirect)($request, $moduleKey, 'error', 'Brak konfiguracji bazy danych.');
        }

        $connection = $this->entityManager->getConnection();
        $template = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where($primaryKey . ' = :id')
            ->setParameter('id', $recordId)
            ->executeQuery()
            ->fetchAssociative();

        if (!$template) {
            return ($this->buildModuleAlertRedirect)($request, $moduleKey, 'error', 'Nie znaleziono szablonu.');
        }

        $subject = (string) ($template['subject'] ?? '');
        $body = (string) ($template['body'] ?? '');

        $sendResult = $this->mailer->sendWithError($user->getEmail(), $subject, $body, true, $this->resolveTemplateBcc($template), false, $this->isPriorityEmailTemplate($template));
        if (!$sendResult['success']) {
            $rawError = $sendResult['error'] ?? null;
            $errorReason = is_string($rawError) ? trim($rawError) : '';
            if ($errorReason === '') {
                $errorReason = 'Nieznany błąd.';
            }

            return ($this->buildModuleAlertRedirect)(
                $request,
                $moduleKey,
                'error',
                'Nie udało się wysłać testowego e-maila. Powód: ' . $errorReason
            );
        }

        ($this->recordModuleChange)($request, $moduleKey, 'test_email', (int) $recordId);

        return ($this->buildModuleAlertRedirect)($request, $moduleKey, 'success', 'Wiadomość testowa została wysłana.');
    }
    /**
     * @param array<string, mixed> $template
     */
    protected function isPriorityEmailTemplate(array $template): bool
    {
        return filter_var($template['priority_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string, mixed> $template
     */
    private function resolveTemplateBcc(array $template): ?string
    {
        $bcc = trim((string) ($template['bcc'] ?? ''));

        return $bcc !== '' ? $bcc : null;
    }
}
