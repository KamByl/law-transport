<?php

namespace App\Service;

use App\Entity\Group;
use App\Entity\GroupPermission;
use App\Entity\Permission;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class InstallationSeeder
{
    private EntityManagerInterface $entityManager;
    private ?ModuleDefinitionLoader $definitionLoader;

    public function __construct(EntityManagerInterface $entityManager, ?ModuleDefinitionLoader $definitionLoader = null)
    {
        $this->entityManager = $entityManager;
        $this->definitionLoader = $definitionLoader;
    }

    /**
     * @param array{email: string, password: string, firstName?: string, lastName?: string}|null $adminUser
     */
    public function seed(?array $adminUser = null): void
    {
        $permissions = $this->seedPermissions();
        $adminGroup = $this->seedAdminGroup();
        $this->seedGroupPermissions($adminGroup, $permissions);
        $this->seedDefaultUser($adminGroup, $adminUser);

        $this->entityManager->flush();
    }

    /**
     * @return Permission[]
     */
    private function seedPermissions(): array
    {
        $definitions = PermissionDefinitionProvider::getCurrentDefinitions($this->definitionLoader);
        $permissions = [];
        $repository = $this->entityManager->getRepository(Permission::class);

        foreach ($definitions as $definition) {
            $permission = $repository->findOneBy(['code' => $definition['code']]);

            if (!$permission) {
                $permission = new Permission();
                $permission->setCode($definition['code']);
                $this->entityManager->persist($permission);
            }

            $permission->setLabel($definition['label']);

            $permissions[] = $permission;
        }

        return $permissions;
    }

    private function seedAdminGroup(): Group
    {
        $repository = $this->entityManager->getRepository(Group::class);
        $group = $repository->findOneBy(['name' => 'Administratorzy']);

        if (!$group) {
            $group = new Group();
            $group->setName('Administratorzy');
            $this->entityManager->persist($group);
        }

        return $group;
    }

    /**
     * @param Permission[] $permissions
     */
    private function seedGroupPermissions(Group $group, array $permissions): void
    {
        $repository = $this->entityManager->getRepository(GroupPermission::class);

        foreach ($permissions as $permission) {
            $groupPermission = $repository->findOneBy([
                'group' => $group,
                'permission' => $permission,
            ]);

            if (!$groupPermission) {
                $groupPermission = new GroupPermission();
                $groupPermission->setGroup($group);
                $groupPermission->setPermission($permission);
                $this->entityManager->persist($groupPermission);
            }

            $groupPermission->setAllowed(true);
            $groupPermission->setCanView(true);
            $groupPermission->setCanCreate(true);
            $groupPermission->setCanEdit(true);
            $groupPermission->setCanDelete(true);
            $groupPermission->setCanStats(true);
        }
    }

    /**
     * @param array{email: string, password: string, firstName?: string, lastName?: string}|null $adminUser
     */
    private function seedDefaultUser(Group $group, ?array $adminUser): void
    {
        if ($adminUser === null) {
            return;
        }

        $repository = $this->entityManager->getRepository(User::class);
        if ($repository->count([]) > 0) {
            return;
        }

        $email = trim((string) ($adminUser['email'] ?? ''));
        $password = (string) ($adminUser['password'] ?? '');

        if ($email === '' || $password === '') {
            return;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName(trim((string) ($adminUser['firstName'] ?? 'Administrator')) ?: 'Administrator');
        $user->setLastName(trim((string) ($adminUser['lastName'] ?? 'Główny')) ?: 'Główny');
        $user->setActive(true);
        $user->setPasswordHash(password_hash($password, PASSWORD_BCRYPT));
        $user->addGroup($group);

        $this->entityManager->persist($user);
    }
}
