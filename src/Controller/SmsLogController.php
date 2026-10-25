<?php

namespace App\Controller;

use App\Entity\SmsLog;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SmsLogController extends BaseController
{
    public function index(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('sms_logs', 'view')) {
            return $response;
        }

        $query = trim((string) $request->query->get('q', ''));

        $qb = $this->entityManager->createQueryBuilder()
            ->select('l', 'u')
            ->from(SmsLog::class, 'l')
            ->leftJoin('l.user', 'u')
            ->orderBy('l.sentAt', 'DESC');

        if ($query !== '') {
            $qb->andWhere('l.recipient LIKE :query OR l.email LIKE :query OR l.context LIKE :query OR l.message LIKE :query OR l.smsapiId LIKE :query OR l.deliveryStatus LIKE :query OR u.firstName LIKE :query OR u.lastName LIKE :query')
                ->setParameter('query', '%' . $query . '%');
        }

        $logs = $qb->setMaxResults(200)->getQuery()->getResult();

        return $this->render('backend/sms_logs.html.twig', [
            'user' => $this->authService->user(),
            'logs' => $logs,
            'query' => $query,
        ]);
    }
}
