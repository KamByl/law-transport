<?php

namespace App\Controller;

use App\Entity\Permission;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionController extends BaseController
{
    public function index(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('permissions', 'view')) {
            return $response;
        }

        $query = trim((string) $request->query->get('q', ''));
        $sort = (string) $request->query->get('sort', 'id');
        $dir = strtolower((string) $request->query->get('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $allowedSorts = ['id', 'code', 'label'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'id';
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Permission::class, 'p')
            ->orderBy('p.' . $sort, $dir);

        if ($query !== '') {
            $qb->andWhere('p.code LIKE :query OR p.label LIKE :query')
                ->setParameter('query', '%' . $query . '%');
        }

        $permissions = $qb->getQuery()->getResult();

        return $this->render('backend/permissions.html.twig', [
            'user' => $this->authService->user(),
            'permissions' => $permissions,
            'query' => $query,
            'sort' => $sort,
            'dir' => $dir,
        ]);
    }

    public function create(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('permissions', 'create')) {
            return $response;
        }

        $permission = new Permission();
        $errors = [];

        if ($request->isMethod('POST')) {
            $code = trim((string) $request->request->get('code'));
            $label = trim((string) $request->request->get('label'));

            $permission->setCode($code);
            $permission->setLabel($label);

            if ($code === '') {
                $errors[] = 'Kod uprawnienia jest wymagany.';
            }
            if ($label === '') {
                $errors[] = 'Nazwa uprawnienia jest wymagana.';
            }

            if ($errors === []) {
                $this->entityManager->persist($permission);
                $this->entityManager->flush();

                return new RedirectResponse('/cms/permissions');
            }
        }

        return $this->render('backend/permission_form.html.twig', [
            'user' => $this->authService->user(),
            'permission' => $permission,
            'errors' => $errors,
            'title' => 'Dodaj uprawnienie',
        ]);
    }

    public function edit(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('permissions', 'edit')) {
            return $response;
        }

        $permission = $this->entityManager->find(Permission::class, (int) ($parameters['id'] ?? 0));
        $errors = [];

        if (!$permission) {
            return new RedirectResponse('/cms/permissions');
        }

        if ($request->isMethod('POST')) {
            $code = trim((string) $request->request->get('code'));
            $label = trim((string) $request->request->get('label'));

            $permission->setCode($code);
            $permission->setLabel($label);

            if ($code === '') {
                $errors[] = 'Kod uprawnienia jest wymagany.';
            }
            if ($label === '') {
                $errors[] = 'Nazwa uprawnienia jest wymagana.';
            }

            if ($errors === []) {
                $this->entityManager->flush();

                return new RedirectResponse('/cms/permissions');
            }
        }

        return $this->render('backend/permission_form.html.twig', [
            'user' => $this->authService->user(),
            'permission' => $permission,
            'errors' => $errors,
            'title' => 'Edytuj uprawnienie',
        ]);
    }

    public function delete(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('permissions', 'delete')) {
            return $response;
        }

        $permission = $this->entityManager->find(Permission::class, (int) ($parameters['id'] ?? 0));

        if ($permission) {
            $this->entityManager->remove($permission);
            $this->entityManager->flush();
        }

        return new RedirectResponse('/cms/permissions');
    }
}
