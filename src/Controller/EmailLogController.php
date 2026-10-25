<?php

namespace App\Controller;

use App\Entity\EmailLog;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailLogController extends BaseController
{
    public function index(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('email_logs', 'view')) {
            return $response;
        }

        $query = trim((string) $request->query->get('q', ''));
        $emailQuery = trim((string) $request->query->get('email', ''));

        $qb = $this->entityManager->createQueryBuilder()
            ->select('l', 'u')
            ->from(EmailLog::class, 'l')
            ->leftJoin('l.user', 'u')
            ->orderBy('l.sentAt', 'DESC');

        if ($query !== '') {
            $qb->andWhere('l.recipient LIKE :query OR l.bcc LIKE :query OR l.email LIKE :query OR l.subject LIKE :query OR l.context LIKE :query OR l.error LIKE :query OR u.firstName LIKE :query OR u.lastName LIKE :query')
                ->setParameter('query', '%' . $query . '%');
        }

        if ($emailQuery !== '') {
            $qb->andWhere('l.email LIKE :emailQuery OR l.recipient LIKE :emailQuery OR l.bcc LIKE :emailQuery OR u.email LIKE :emailQuery')
                ->setParameter('emailQuery', '%' . $emailQuery . '%');
        }

        $export = strtolower(trim((string) $request->query->get('export', '')));
        if ($export === 'csv') {
            $logs = $qb->getQuery()->getResult();

            return $this->createCsvResponse($logs);
        }

        $logs = $qb->setMaxResults(200)->getQuery()->getResult();

        return $this->render('backend/email_logs.html.twig', [
            'user' => $this->authService->user(),
            'logs' => $logs,
            'query' => $query,
            'emailQuery' => $emailQuery,
        ]);
    }

    /**
     * @param EmailLog[] $logs
     */
    private function createCsvResponse(array $logs): StreamedResponse
    {
        $filename = sprintf('email-logs-%s.csv', (new \DateTimeImmutable())->format('Ymd_His'));

        $response = new StreamedResponse(static function () use ($logs): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Data', 'Odbiorca', 'UDW', 'Temat', 'Użytkownik', 'Email', 'Kontekst', 'Status', 'Błąd', 'IP', 'Treść'], ';', '"', '');

            foreach ($logs as $log) {
                $user = $log->getUser();
                $userLabel = $user ? trim($user->getFirstName() . ' ' . $user->getLastName()) : 'System';
                if ($userLabel === '') {
                    $userLabel = 'System';
                }

                fputcsv($output, [
                    $log->getSentAt()?->format('Y-m-d H:i:s') ?? '',
                    $log->getRecipient(),
                    $log->getBcc() ?? '',
                    $log->getSubject(),
                    $userLabel,
                    $log->getEmail(),
                    $log->getContext(),
                    $log->isSuccess() ? 'OK' : 'Błąd',
                    $log->getError() ?? '',
                    $log->getIpAddress() ?? '',
                    $log->getBody(),
                ], ';', '"', '');
            }

            fclose($output);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }
}
