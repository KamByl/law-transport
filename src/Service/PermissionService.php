<?php

namespace App\Service;

use App\Entity\GroupPermission;
use App\Entity\Permission;
use App\Entity\User;
use App\Entity\UserPermission;
use Doctrine\ORM\EntityManagerInterface;

class PermissionService
{
    public const ACTION_VIEW = 'view';
    public const ACTION_CREATE = 'create';
    public const ACTION_EDIT = 'edit';
    public const ACTION_DELETE = 'delete';
    public const ACTION_STATS = 'stats';

    private EntityManagerInterface $entityManager;

    private const ADMINISTRATOR_GROUP_NAME = 'Administratorzy';

    /**
     * @var string[]|null
     */
    private ?array $currentPermissionCodes = null;

    /**
     * @var array<string, string[]>
     */
    private const PERMISSION_CODE_ALIASES = [
        'email_logs' => ['email_log'],
        'email_log' => ['email_logs'],
        'sms_logs' => ['sms_log'],
        'sms_log' => ['sms_logs'],
        'login_logs' => ['login_log'],
        'login_log' => ['login_logs'],
        'module_change_logs' => ['module_change_log'],
        'module_change_log' => ['module_change_logs'],
    ];

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function can(?User $user, string $code, string $action): bool
    {
        if (!$user) {
            return false;
        }

        $action = strtolower($action);
        if (!in_array($action, [self::ACTION_VIEW, self::ACTION_CREATE, self::ACTION_EDIT, self::ACTION_DELETE, self::ACTION_STATS], true)) {
            return false;
        }

        if ($this->isAdministrator($user) && $this->isCurrentPermissionCode($code)) {
            return true;
        }

        $effectiveCodes = $this->resolveEffectivePermissionCodes($code);
        if ($effectiveCodes === []) {
            return false;
        }

        foreach ($effectiveCodes as $effectiveCode) {
            $explicit = $this->resolveUserPermission($user, $effectiveCode, $action);
            if ($explicit !== null) {
                return $explicit;
            }

            if ($this->isAllowedByGroup($user, $effectiveCode, $action)) {
                return true;
            }
        }

        return false;
    }

    private function isAdministrator(User $user): bool
    {
        foreach ($user->getGroups() as $group) {
            if ($group->getName() === self::ADMINISTRATOR_GROUP_NAME) {
                return true;
            }
        }

        return false;
    }

    private function isCurrentPermissionCode(string $code): bool
    {
        $currentCodes = $this->getCurrentPermissionCodes();

        if (in_array($code, $currentCodes, true)) {
            return true;
        }

        foreach (self::PERMISSION_CODE_ALIASES[$code] ?? [] as $aliasCode) {
            if (in_array($aliasCode, $currentCodes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function getCurrentPermissionCodes(): array
    {
        if ($this->currentPermissionCodes === null) {
            $this->currentPermissionCodes = PermissionDefinitionProvider::getCurrentCodes(new ModuleDefinitionLoader());
        }

        return $this->currentPermissionCodes;
    }

    /**
     * @return string[]
     */
    private function resolveEffectivePermissionCodes(string $code): array
    {
        $codes = [$code];
        foreach (self::PERMISSION_CODE_ALIASES[$code] ?? [] as $aliasCode) {
            $codes[] = $aliasCode;
        }

        $uniqueCodes = array_values(array_unique($codes));
        $repository = $this->entityManager->getRepository(Permission::class);
        $permissions = $repository->findBy(['code' => $uniqueCodes]);

        $existingCodes = [];
        foreach ($permissions as $permission) {
            if (!$permission instanceof Permission) {
                continue;
            }

            $existingCodes[$permission->getCode()] = true;
        }

        return array_values(array_filter(
            $uniqueCodes,
            static fn(string $candidateCode): bool => isset($existingCodes[$candidateCode])
        ));
    }

    private function resolveUserPermission(User $user, string $code, string $action): ?bool
    {
        foreach ($user->getUserPermissions() as $userPermission) {
            if (!$userPermission instanceof UserPermission) {
                continue;
            }

            $permission = $userPermission->getPermission();
            if ($permission && $permission->getCode() === $code) {
                if ($this->isActionAllowed($userPermission, $action)) {
                    return true;
                }

                return null;
            }
        }

        return null;
    }

    private function isAllowedByGroup(User $user, string $code, string $action): bool
    {
        foreach ($user->getGroups() as $group) {
            foreach ($group->getPermissions() as $groupPermission) {
                if (!$groupPermission instanceof GroupPermission) {
                    continue;
                }

                $permission = $groupPermission->getPermission();
                if ($permission && $permission->getCode() === $code && $this->isActionAllowed($groupPermission, $action)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isActionAllowed(UserPermission|GroupPermission $permission, string $action): bool
    {
        return match ($action) {
            self::ACTION_VIEW => $permission->isCanView(),
            self::ACTION_CREATE => $permission->isCanCreate(),
            self::ACTION_EDIT => $permission->isCanEdit(),
            self::ACTION_DELETE => $permission->isCanDelete(),
            self::ACTION_STATS => $permission->isCanStats(),
            default => false,
        };
    }
}
