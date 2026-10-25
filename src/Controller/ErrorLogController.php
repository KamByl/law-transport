<?php

namespace App\Controller;

use App\Entity\ErrorLog;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ErrorLogController extends BaseController
{
    public function index(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('error_logs', 'view')) {
            return $response;
        }

        $query = trim((string) $request->query->get('q', ''));
        $emailQuery = trim((string) $request->query->get('email', ''));

        $qb = $this->entityManager->createQueryBuilder()
            ->select('e', 'u')
            ->from(ErrorLog::class, 'e')
            ->leftJoin('e.user', 'u')
            ->orderBy('e.createdAt', 'DESC');

        if ($query !== '') {
            $qb->andWhere('e.url LIKE :query OR e.exceptionMessage LIKE :query OR e.ipAddress LIKE :query OR u.email LIKE :query')
                ->setParameter('query', '%' . $query . '%');
        }

        if ($emailQuery !== '') {
            $qb->andWhere('u.email LIKE :emailQuery')
                ->setParameter('emailQuery', '%' . $emailQuery . '%');
        }

        $logs = $qb->setMaxResults(300)->getQuery()->getResult();

        return $this->render('backend/error_logs.html.twig', [
            'user' => $this->authService->user(),
            'logs' => $logs,
            'query' => $query,
            'emailQuery' => $emailQuery,
        ]);
    }
}
