<?php

namespace App\Controller;

use App\Entity\EmailQueue;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailQueueController extends BaseController
{
    public function index(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('email_queue', 'view')) {
            return $response;
        }

        $query = trim((string) $request->query->get('q', ''));
        $emailQuery = trim((string) $request->query->get('email', ''));
        $status = trim((string) $request->query->get('status', ''));

        $allowedStatuses = ['pending', 'processing', 'failed', 'sent'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = '';
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('q')
            ->from(EmailQueue::class, 'q');

        if ($query !== '') {
            $qb->andWhere('q.recipient LIKE :query OR q.bcc LIKE :query OR q.subject LIKE :query OR q.body LIKE :query OR q.lastError LIKE :query')
                ->setParameter('query', '%' . $query . '%');
        }

        if ($emailQuery !== '') {
            $qb->andWhere('q.recipient LIKE :emailQuery OR q.bcc LIKE :emailQuery')
                ->setParameter('emailQuery', '%' . $emailQuery . '%');
        }

        if ($status !== '') {
            $qb->andWhere('q.status = :status')
                ->setParameter('status', $status);
        }

        $export = strtolower(trim((string) $request->query->get('export', '')));
        if ($export === 'csv') {
            $items = (clone $qb)
                ->orderBy('q.createdAt', 'DESC')
                ->getQuery()
                ->getResult();

            return $this->createCsvResponse($items);
        }

        $perPage = 50;
        $page = max(1, (int) $request->query->get('page', 1));

        $allItemsCount = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(all_queue.id)')
            ->from(EmailQueue::class, 'all_queue')
            ->getQuery()
            ->getSingleScalarResult();

        $filteredItemsCount = (int) (clone $qb)
            ->select('COUNT(q.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $failedItemsCount = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(failed_queue.id)')
            ->from(EmailQueue::class, 'failed_queue')
            ->where('failed_queue.status = :failedStatus')
            ->setParameter('failedStatus', 'failed')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = max(1, (int) ceil($filteredItemsCount / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $items = $qb
            ->orderBy('q.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $testEmailStatus = trim((string) $request->query->get('test_email_status', ''));
        $testEmailMessage = trim((string) $request->query->get('test_email_message', ''));
        $testEmailRecipient = trim((string) $request->query->get('test_email_recipient', ''));
        $testEmailAlert = null;
        if ($testEmailStatus !== '' && $testEmailMessage !== '') {
            $testEmailAlert = [
                'type' => $testEmailStatus === 'success' ? 'success' : 'danger',
                'message' => $testEmailMessage,
            ];
        }

        $queueActionStatus = trim((string) $request->query->get('queue_status', ''));
        $queueActionMessage = trim((string) $request->query->get('queue_message', ''));
        $queueActionAlert = null;
        if ($queueActionStatus !== '' && $queueActionMessage !== '') {
            $queueActionAlert = [
                'type' => $queueActionStatus === 'success' ? 'success' : 'danger',
                'message' => $queueActionMessage,
            ];
        }

        return $this->render('backend/email_queue.html.twig', [
            'user' => $this->authService->user(),
            'items' => $items,
            'query' => $query,
            'emailQuery' => $emailQuery,
            'status' => $status,
            'statuses' => $allowedStatuses,
            'testEmailAlert' => $testEmailAlert,
            'testEmailRecipient' => $testEmailRecipient,
            'queueActionAlert' => $queueActionAlert,
            'allItemsCount' => $allItemsCount,
            'filteredItemsCount' => $filteredItemsCount,
            'failedItemsCount' => $failedItemsCount,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }

    /**
     * @param EmailQueue[] $items
     */
    private function createCsvResponse(array $items): StreamedResponse
    {
        $filename = sprintf('email-queue-%s.csv', (new \DateTimeImmutable())->format('Ymd_His'));

        $response = new StreamedResponse(static function () use ($items): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'ID',
                'Data dodania',
                'Odbiorca',
                'Temat',
                'Status',
                'Priorytet',
                'Próby',
                'Maks. prób',
                'Dostępny od',
                'Zablokowano',
                'Wysłano',
                'BCC',
                'Ostatni błąd',
                'Treść',
            ], ';', '"', '');

            foreach ($items as $item) {
                fputcsv($output, [
                    $item->getId(),
                    $item->getCreatedAt()?->format('Y-m-d H:i:s') ?? '',
                    $item->getRecipient(),
                    $item->getSubject(),
                    $item->getStatus(),
                    $item->isPriority() ? 'TAK' : 'NIE',
                    $item->getAttempts(),
                    $item->getMaxAttempts(),
                    $item->getAvailableAt()?->format('Y-m-d H:i:s') ?? '',
                    $item->getLockedAt()?->format('Y-m-d H:i:s') ?? '',
                    $item->getSentAt()?->format('Y-m-d H:i:s') ?? '',
                    $item->getBcc() ?? '',
                    $item->getLastError() ?? '',
                    $item->getBody(),
                ], ';', '"', '');
            }

            fclose($output);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    public function sendTestEmail(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('email_queue', 'view')) {
            return $response;
        }

        $recipient = trim((string) $request->request->get('recipient', ''));
        if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return $this->redirectWithMessage('danger', 'Podaj poprawny adres e-mail do wysyłki testowej.', $recipient);
        }

        $subject = 'Testowa wiadomość z CMS';
        $body = sprintf(
            '<p>To jest testowa wiadomość wysłana bezpośrednio z panelu CMS.</p><p><strong>Data wysyłki:</strong> %s</p>',
            (new \DateTimeImmutable())->format('Y-m-d H:i:s')
        );

        $result = $this->mailer->sendWithError($recipient, $subject, $body, true, null, true);
        if ($result['success']) {
            return $this->redirectWithMessage('success', 'Testowy e-mail został wysłany od razu (z pominięciem kolejki).', $recipient);
        }

        $error = trim((string) ($result['error'] ?? ''));
        if ($error === '') {
            $error = 'Nieznany błąd podczas wysyłki testowego e-maila.';
        }

        return $this->redirectWithMessage('danger', 'Nie udało się wysłać testowego e-maila: ' . $error, $recipient);
    }

    public function retryFailed(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('email_queue', 'view')) {
            return $response;
        }

        $item = $this->entityManager->find(EmailQueue::class, (int) ($parameters['id'] ?? 0));
        if (!$item instanceof EmailQueue) {
            return $this->redirectWithQueueMessage('danger', 'Nie znaleziono wiadomości w kolejce.');
        }
        if ($item->getStatus() !== 'failed') {
            return $this->redirectWithQueueMessage('danger', 'Ponowić można tylko wiadomość ze statusem FAILED.');
        }

        $this->resetFailedItem($item);
        $this->entityManager->flush();

        return $this->redirectWithQueueMessage('success', sprintf(
            'Wiadomość #%d została ponownie dodana do kolejki.',
            (int) $item->getId()
        ));
    }

    public function retryAllFailed(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('email_queue', 'view')) {
            return $response;
        }

        $retried = $this->entityManager->createQuery(
            'UPDATE App\\Entity\\EmailQueue q
             SET q.status = :pending,
                 q.attempts = 0,
                 q.lastError = NULL,
                 q.lockedAt = NULL,
                 q.sentAt = NULL,
                 q.availableAt = :availableAt
             WHERE q.status = :failed'
        )
            ->setParameter('pending', 'pending')
            ->setParameter('availableAt', new \DateTimeImmutable())
            ->setParameter('failed', 'failed')
            ->execute();

        return $this->redirectWithQueueMessage('success', sprintf(
            'Ponownie dodano do kolejki: %d wiadomości.',
            (int) $retried
        ));
    }

    private function resetFailedItem(EmailQueue $item): void
    {
        $item->setStatus('pending');
        $item->setAttempts(0);
        $item->setLastError(null);
        $item->setLockedAt(null);
        $item->setSentAt(null);
        $item->setAvailableAt(new \DateTimeImmutable());
    }

    private function redirectWithQueueMessage(string $status, string $message): RedirectResponse
    {
        return new RedirectResponse('/cms/email-queue?' . http_build_query([
            'status' => 'failed',
            'queue_status' => $status,
            'queue_message' => $message,
        ]), Response::HTTP_SEE_OTHER);
    }

    private function redirectWithMessage(string $status, string $message, string $recipient): RedirectResponse
    {
        $query = http_build_query([
            'test_email_status' => $status,
            'test_email_message' => $message,
            'test_email_recipient' => $recipient,
        ]);

        return new RedirectResponse('/cms/email-queue?' . $query, Response::HTTP_SEE_OTHER);
    }
}
