<?php

namespace App\Controller;

use App\Entity\LoginLog;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class LoginLogController extends BaseController
{
    public function index(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('login_logs', 'view')) {
            return $response;
        }

        $query = trim((string) $request->query->get('q', ''));

        $qb = $this->entityManager->createQueryBuilder()
            ->select('l', 'u')
            ->from(LoginLog::class, 'l')
            ->leftJoin('l.user', 'u')
            ->orderBy('l.loggedAt', 'DESC');

        if ($query !== '') {
            $qb->andWhere('l.email LIKE :query OR l.ipAddress LIKE :query OR u.firstName LIKE :query OR u.lastName LIKE :query')
                ->setParameter('query', '%' . $query . '%');
        }

        $logs = $qb->setMaxResults(200)->getQuery()->getResult();

        return $this->render('backend/login_logs.html.twig', [
            'user' => $this->authService->user(),
            'logs' => $logs,
            'query' => $query,
        ]);
    }
}
