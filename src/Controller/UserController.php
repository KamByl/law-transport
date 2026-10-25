<?php

namespace App\Controller;

use App\Entity\Group;
use App\Entity\Permission;
use App\Entity\User;
use App\Entity\UserPermission;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class UserController extends BaseController
{
    public function index(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('users', 'view')) {
            return $response;
        }

        $query = trim((string) $request->query->get('q', ''));
        $sort = (string) $request->query->get('sort', 'id');
        $dir = strtolower((string) $request->query->get('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $allowedSorts = ['id', 'firstName', 'lastName', 'email', 'active', 'group'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'id';
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('u', 'g')
            ->from(User::class, 'u')
            ->leftJoin('u.groups', 'g');

        if ($sort === 'group') {
            $qb->orderBy('CASE WHEN g.name IS NULL THEN 1 ELSE 0 END', 'asc')
                ->addOrderBy('g.name', $dir)
                ->addOrderBy('u.lastName', 'asc')
                ->addOrderBy('u.firstName', 'asc');
        } else {
            $qb->orderBy('u.' . $sort, $dir);
        }

        if ($query !== '') {
            $qb->andWhere('u.firstName LIKE :query OR u.lastName LIKE :query OR u.email LIKE :query')
                ->setParameter('query', '%' . $query . '%');
        }

        $users = $qb->getQuery()->getResult();

        return $this->render('backend/users.html.twig', [
            'users' => $users,
            'user' => $this->authService->user(),
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

        if ($response = $this->denyUnlessPermission('users', 'create')) {
            return $response;
        }

        $user = new User();
        $errors = [];
        $groups = $this->entityManager->getRepository(Group::class)->findAll();
        $selectedGroupId = null;
        $permissions = $this->findCurrentPermissions();
        $permissionActions = [];

        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email'));
            $firstName = trim((string) $request->request->get('first_name'));
            $lastName = trim((string) $request->request->get('last_name'));
            $phone = trim((string) $request->request->get('phone'));
            $password = (string) $request->request->get('password');
            $active = $request->request->getBoolean('active');
            $canImpersonate = $request->request->getBoolean('can_impersonate');
            $selectedGroupId = $this->normalizeGroupId($request->request->get('group_id'));
            $permissionActions = $this->normalizePermissionActions($request->request->all('permission_actions'));

            if ($firstName === '') {
                $errors[] = 'Imię jest wymagane.';
            }
            if ($lastName === '') {
                $errors[] = 'Nazwisko jest wymagane.';
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Podaj poprawny adres e-mail.';
            }
            if ($password === '' || mb_strlen($password) < 8) {
                $errors[] = 'Hasło musi mieć co najmniej 8 znaków.';
            }

            if ($errors === []) {
                $user->setEmail($email);
                $user->setFirstName($firstName);
                $user->setLastName($lastName);
                $user->setPhone($phone !== '' ? $phone : null);
                $user->setActive($active);
                $user->setCanImpersonate($canImpersonate);
                $user->setPasswordHash(password_hash($password, PASSWORD_BCRYPT));
                $this->syncUserGroup($user, $selectedGroupId);
                $this->syncUserPermissions($user, $permissionActions);

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                return new RedirectResponse('/cms/users');
            }
        }

        return $this->render('backend/user_form.html.twig', [
            'user' => $this->authService->user(),
            'formUser' => $user,
            'errors' => $errors,
            'title' => 'Dodaj użytkownika',
            'requirePassword' => true,
            'groups' => $groups,
            'selectedGroupId' => $selectedGroupId,
            'permissions' => $permissions,
            'permissionActions' => $permissionActions,
        ]);
    }

    public function edit(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('users', 'edit')) {
            return $response;
        }

        $user = $this->entityManager->find(User::class, (int) ($parameters['id'] ?? 0));
        $errors = [];
        $groups = $this->entityManager->getRepository(Group::class)->findAll();
        $selectedGroupId = null;
        $permissions = $this->findCurrentPermissions();
        $permissionActions = [];

        if (!$user) {
            return new RedirectResponse('/cms/users');
        }

        $selectedGroupId = $this->resolveSelectedGroupId($user);
        $permissionActions = $this->buildPermissionActionMap($user->getUserPermissions()->toArray());

        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email'));
            $firstName = trim((string) $request->request->get('first_name'));
            $lastName = trim((string) $request->request->get('last_name'));
            $phone = trim((string) $request->request->get('phone'));
            $password = (string) $request->request->get('password');
            $active = $request->request->getBoolean('active');
            $canImpersonate = $request->request->getBoolean('can_impersonate');
            $selectedGroupId = $this->normalizeGroupId($request->request->get('group_id'));
            $permissionActions = $this->normalizePermissionActions($request->request->all('permission_actions'));

            $user->setEmail($email);
            $user->setFirstName($firstName);
            $user->setLastName($lastName);
            $user->setPhone($phone !== '' ? $phone : null);
            $user->setActive($active);
            $user->setCanImpersonate($canImpersonate);

            if ($firstName === '') {
                $errors[] = 'Imię jest wymagane.';
            }
            if ($lastName === '') {
                $errors[] = 'Nazwisko jest wymagane.';
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Podaj poprawny adres e-mail.';
            }
            if ($password !== '' && mb_strlen($password) < 8) {
                $errors[] = 'Hasło musi mieć co najmniej 8 znaków.';
            }

            if ($errors === []) {
                if ($password !== '') {
                    $user->setPasswordHash(password_hash($password, PASSWORD_BCRYPT));
                }
                $this->syncUserGroup($user, $selectedGroupId);
                $this->syncUserPermissions($user, $permissionActions);

                $this->entityManager->flush();

                return new RedirectResponse('/cms/users');
            }
        }

        return $this->render('backend/user_form.html.twig', [
            'user' => $this->authService->user(),
            'formUser' => $user,
            'errors' => $errors,
            'title' => 'Edytuj użytkownika',
            'requirePassword' => false,
            'groups' => $groups,
            'selectedGroupId' => $selectedGroupId,
            'permissions' => $permissions,
            'permissionActions' => $permissionActions,
        ]);
    }

    public function delete(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('users', 'delete')) {
            return $response;
        }

        $user = $this->entityManager->find(User::class, (int) ($parameters['id'] ?? 0));

        if ($user) {
            $this->entityManager->remove($user);
            $this->entityManager->flush();
        }

        return new RedirectResponse('/cms/users');
    }

    public function profile(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        $user = $this->authService->user();
        $message = null;

        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password');
            if ($password !== '') {
                $user->setPasswordHash(password_hash($password, PASSWORD_BCRYPT));
                $this->entityManager->flush();
                $message = 'Hasło zostało zmienione.';
            }
        }

        return $this->render('backend/profile.html.twig', [
            'user' => $user,
            'message' => $message,
        ]);
    }

    public function impersonate(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        $target = $this->entityManager->find(User::class, (int) ($parameters['id'] ?? 0));
        $actor = $this->authService->user();
        if (
            !$target
            || !$target->isActive()
            || !$actor
            || !$actor->canImpersonate()
            || $target->getId() === $actor->getId()
        ) {
            return new RedirectResponse('/cms/users');
        }

        $this->authService->impersonate(
            $target,
            $actor,
            $request->getClientIp(),
            $request->headers->get('User-Agent')
        );

        // Podgląd systemu oczami użytkownika odbywa się we frontendzie.
        return new RedirectResponse('/');
    }

    public function stopImpersonation(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        $impersonator = $this->authService->impersonator();
        if (!$impersonator) {
            return new RedirectResponse('/cms');
        }

        $this->authService->impersonate(
            $impersonator,
            null,
            $request->getClientIp(),
            $request->headers->get('User-Agent')
        );

        return new RedirectResponse('/cms');
    }

    /**
     */
    private function syncUserGroup(User $user, ?int $groupId): void
    {
        foreach ($user->getGroups() as $group) {
            $user->removeGroup($group);
        }

        if ($groupId === null) {
            return;
        }

        $group = $this->entityManager->getRepository(Group::class)->find($groupId);

        if ($group) {
            $user->addGroup($group);
        }
    }

    private function resolveSelectedGroupId(User $user): ?int
    {
        $group = $user->getGroups()->first();

        return $group instanceof Group ? $group->getId() : null;
    }

    private function normalizeGroupId(mixed $value): ?int
    {
        $groupId = filter_var($value, FILTER_VALIDATE_INT);

        return $groupId ? (int) $groupId : null;
    }

    /**
     * @param array<int, array{view: bool, create: bool, edit: bool, delete: bool, stats: bool}> $permissionActions
     */
    private function syncUserPermissions(User $user, array $permissionActions): void
    {
        $currentPermissionCodes = array_flip($this->getCurrentPermissionCodes());
        $existing = [];
        foreach ($user->getUserPermissions() as $userPermission) {
            if (!$userPermission instanceof UserPermission) {
                continue;
            }

            $permission = $userPermission->getPermission();
            $permissionId = $permission?->getId();
            $isCurrentPermission = $permission !== null && isset($currentPermissionCodes[$permission->getCode()]);
            if ($permissionId !== null && $isCurrentPermission) {
                $existing[$permissionId] = $userPermission;
            }

            if (!$isCurrentPermission || $permissionId === null || !$this->hasAnyPermissionAction($permissionActions[$permissionId] ?? null)) {
                $user->removeUserPermission($userPermission);
                $this->entityManager->remove($userPermission);
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

            $userPermission = $existing[$permissionId] ?? null;
            if (!$userPermission) {
                $userPermission = new UserPermission();
                $userPermission->setPermission($permission);
                $userPermission->setAllowed(true);
                $user->addUserPermission($userPermission);
                $this->entityManager->persist($userPermission);
            }

            $this->applyPermissionActions($userPermission, $actions);
        }
    }

    /**
     * @param array<int, mixed> $permissions
     * @return array<int, array{view: bool, create: bool, edit: bool, delete: bool, stats: bool}>
     */
    private function buildPermissionActionMap(array $permissions): array
    {
        $mapped = [];

        foreach ($permissions as $userPermission) {
            if (!$userPermission instanceof UserPermission) {
                continue;
            }

            $permissionId = $userPermission->getPermission()?->getId();
            if ($permissionId === null) {
                continue;
            }

            $mapped[$permissionId] = [
                'view' => $userPermission->isCanView(),
                'create' => $userPermission->isCanCreate(),
                'edit' => $userPermission->isCanEdit(),
                'delete' => $userPermission->isCanDelete(),
                'stats' => $userPermission->isCanStats(),
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
    private function applyPermissionActions(UserPermission $permission, array $actions): void
    {
        $permission->setCanView($actions['view']);
        $permission->setCanCreate($actions['create']);
        $permission->setCanEdit($actions['edit']);
        $permission->setCanDelete($actions['delete']);
        $permission->setCanStats($actions['stats']);
    }
}
