<?php

namespace App\Controller;

use App\Entity\ModuleChangeLog;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ModuleChangeLogController extends BaseController
{
    public function index(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('module_change_logs', 'view')) {
            return $response;
        }

        $query = trim((string) $request->query->get('q', ''));

        $qb = $this->entityManager->createQueryBuilder()
            ->select('l', 'u')
            ->from(ModuleChangeLog::class, 'l')
            ->leftJoin('l.user', 'u')
            ->orderBy('l.changedAt', 'DESC');

        if ($query !== '') {
            $qb->andWhere('l.moduleKey LIKE :query OR l.action LIKE :query OR l.email LIKE :query OR u.firstName LIKE :query OR u.lastName LIKE :query')
                ->setParameter('query', '%' . $query . '%');
        }

        $logs = $qb->setMaxResults(200)->getQuery()->getResult();

        return $this->render('backend/module_change_logs.html.twig', [
            'user' => $this->authService->user(),
            'logs' => $logs,
            'query' => $query,
        ]);
    }
}
