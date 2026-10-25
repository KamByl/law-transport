<?php

namespace App\Controller;

use App\Entity\Group;
use App\Entity\GroupPermission;
use App\Entity\Permission;
use App\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class GroupController extends BaseController
{
    public function index(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('groups', 'view')) {
            return $response;
        }

        $query = trim((string) $request->query->get('q', ''));
        $sort = (string) $request->query->get('sort', 'id');
        $dir = strtolower((string) $request->query->get('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $allowedSorts = ['id', 'name'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'id';
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('g')
            ->from(Group::class, 'g')
            ->orderBy('g.' . $sort, $dir);

        if ($query !== '') {
            $qb->andWhere('g.name LIKE :query')
                ->setParameter('query', '%' . $query . '%');
        }

        $groups = $qb->getQuery()->getResult();

        return $this->render('backend/groups.html.twig', [
            'user' => $this->authService->user(),
            'groups' => $groups,
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

        if ($response = $this->denyUnlessPermission('groups', 'create')) {
            return $response;
        }

        $group = new Group();
        $permissions = $this->findCurrentPermissions();
        $permissionActions = [];

        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name'));
            $permissionActions = $this->normalizePermissionActions($request->request->all('permission_actions'));

            if ($name !== '') {
                $group->setName($name);
                $this->entityManager->persist($group);
                $this->syncGroupPermissions($group, $permissionActions);
                $this->entityManager->flush();

                return new RedirectResponse('/cms/groups');
            }
        }

        return $this->render('backend/group_form.html.twig', [
            'user' => $this->authService->user(),
            'group' => $group,
            'title' => 'Dodaj grupę',
            'permissions' => $permissions,
            'permissionActions' => $permissionActions,
        ]);
    }

    public function edit(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('groups', 'edit')) {
            return $response;
        }

        $group = $this->entityManager->find(Group::class, (int) ($parameters['id'] ?? 0));

        if (!$group) {
            return new RedirectResponse('/cms/groups');
        }

        $userRepository = $this->entityManager->getRepository(User::class);
        $users = $userRepository->findBy([], ['lastName' => 'asc', 'firstName' => 'asc']);
        $selectedUserIds = array_map(
            static fn(User $user): int => $user->getId(),
            $group->getUsers()->toArray()
        );
        $permissions = $this->findCurrentPermissions();
        $permissionActions = $this->buildPermissionActionMap($group->getPermissions()->toArray());

        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name'));
            $selectedUserIds = $this->normalizeUserIds($request->request->all('user_ids'));
            $permissionActions = $this->normalizePermissionActions($request->request->all('permission_actions'));

            if ($name !== '') {
                $group->setName($name);
                $this->syncGroupUsers($group, $selectedUserIds);
                $this->syncGroupPermissions($group, $permissionActions);
                $this->entityManager->flush();

                return new RedirectResponse('/cms/groups');
            }
        }

        return $this->render('backend/group_form.html.twig', [
            'user' => $this->authService->user(),
            'group' => $group,
            'title' => 'Edytuj grupę',
            'users' => $users,
            'selectedUserIds' => $selectedUserIds,
            'permissions' => $permissions,
            'permissionActions' => $permissionActions,
        ]);
    }

    public function delete(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('groups', 'delete')) {
            return $response;
        }

        $group = $this->entityManager->find(Group::class, (int) ($parameters['id'] ?? 0));

        if ($group) {
            $this->entityManager->remove($group);
            $this->entityManager->flush();
        }

        return new RedirectResponse('/cms/groups');
    }

    /**
     * @param array<int|string, mixed> $userIds
     * @return array<int>
     */
    private function normalizeUserIds(array $userIds): array
    {
        $normalized = [];

        foreach ($userIds as $userId) {
            $id = filter_var($userId, FILTER_VALIDATE_INT);
            if ($id) {
                $normalized[] = (int) $id;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<int> $selectedUserIds
     */
    private function syncGroupUsers(Group $group, array $selectedUserIds): void
    {
        foreach ($group->getUsers() as $user) {
            if (!in_array($user->getId(), $selectedUserIds, true)) {
                $user->removeGroup($group);
            }
        }

        if ($selectedUserIds === []) {
            return;
        }

        $users = $this->entityManager->getRepository(User::class)->findBy(['id' => $selectedUserIds]);

        foreach ($users as $user) {
            $user->addGroup($group);
        }
    }

    /**
     * @param array<int, array{view: bool, create: bool, edit: bool, delete: bool, stats: bool}> $permissionActions
     */
    private function syncGroupPermissions(Group $group, array $permissionActions): void
    {
        $currentPermissionCodes = array_flip($this->getCurrentPermissionCodes());
        $existing = [];
        foreach ($group->getPermissions() as $groupPermission) {
            if (!$groupPermission instanceof GroupPermission) {
                continue;
            }

            $permission = $groupPermission->getPermission();
            $permissionId = $permission?->getId();
            $isCurrentPermission = $permission !== null && isset($currentPermissionCodes[$permission->getCode()]);
            if ($permissionId !== null && $isCurrentPermission) {
                $existing[$permissionId] = $groupPermission;
            }

            if (!$isCurrentPermission || $permissionId === null || !$this->hasAnyPermissionAction($permissionActions[$permissionId] ?? null)) {
                $this->entityManager->remove($groupPermission);
            }
        }

        if ($permissionActions === []) {
            return;
        }

        $permissionIds = array_keys(array_filter(
            $permissionActions,
            fn(?array $actions): bool => $this->hasAnyPermissionAction($actions)
        ));
        if ($permissionIds === []) {
            return;
        }

        $permissions = $this->entityManager->getRepository(Permission::class)
            ->findBy(['id' => $permissionIds, 'code' => array_keys($currentPermissionCodes)]);

        foreach ($permissions as $permission) {
            $permissionId = $permission->getId();
            if ($permissionId === null) {
                continue;
            }

            $actions = $permissionActions[$permissionId] ?? null;
            if (!$this->hasAnyPermissionAction($actions)) {
                continue;
            }

            $groupPermission = $existing[$permissionId] ?? null;
            if (!$groupPermission) {
                $groupPermission = new GroupPermission();
                $groupPermission->setGroup($group);
                $groupPermission->setPermission($permission);
                $groupPermission->setAllowed(true);
                $this->entityManager->persist($groupPermission);
            }

            $this->applyPermissionActions($groupPermission, $actions);
        }
    }

    /**
     * @param array<int, mixed> $permissions
     * @return array<int, array{view: bool, create: bool, edit: bool, delete: bool, stats: bool}>
     */
    private function buildPermissionActionMap(array $permissions): array
    {
        $mapped = [];

        foreach ($permissions as $groupPermission) {
            if (!$groupPermission instanceof GroupPermission) {
                continue;
            }

            $permissionId = $groupPermission->getPermission()?->getId();
            if ($permissionId === null) {
                continue;
            }

            $mapped[$permissionId] = [
                'view' => $groupPermission->isCanView(),
                'create' => $groupPermission->isCanCreate(),
                'edit' => $groupPermission->isCanEdit(),
                'delete' => $groupPermission->isCanDelete(),
                'stats' => $groupPermission->isCanStats(),
            ];
        }

        return $mapped;
    }

    /**
     * @param array<int|string, mixed> $permissionActions
     * @return array<int, array{view: bool, create: bool, edit: bool, delete: bool, stats: bool}>
     */
    private function normalizePermissionActions(array $permissionActions): array
    {
        $normalized = [];

        foreach ($permissionActions as $permissionId => $actions) {
            $id = filter_var($permissionId, FILTER_VALIDATE_INT);
            if (!$id || !is_array($actions)) {
                continue;
            }

            $normalized[(int) $id] = $this->normalizeActionFlags($actions);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $actions
     * @return array{view: bool, create: bool, edit: bool, delete: bool, stats: bool}
     */
    private function normalizeActionFlags(array $actions): array
    {
        return [
            'view' => array_key_exists('view', $actions),
            'create' => array_key_exists('create', $actions),
            'edit' => array_key_exists('edit', $actions),
            'delete' => array_key_exists('delete', $actions),
            'stats' => array_key_exists('stats', $actions),
        ];
    }

    /**
     * @param array{view: bool, create: bool, edit: bool, delete: bool, stats: bool}|null $actions
     */
    private function hasAnyPermissionAction(?array $actions): bool
    {
        if (!$actions) {
            return false;
        }

        return $actions['view'] || $actions['create'] || $actions['edit'] || $actions['delete'] || $actions['stats'];
    }

    /**
     * @param array{view: bool, create: bool, edit: bool, delete: bool, stats: bool} $actions
     */
    private function applyPermissionActions(GroupPermission $permission, array $actions): void
    {
        $permission->setCanView($actions['view']);
        $permission->setCanCreate($actions['create']);
        $permission->setCanEdit($actions['edit']);
        $permission->setCanDelete($actions['delete']);
        $permission->setCanStats($actions['stats']);
    }
}
