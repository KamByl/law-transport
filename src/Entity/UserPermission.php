<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_permissions')]
class UserPermission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'userPermissions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Permission::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Permission $permission = null;

    #[ORM\Column(type: 'boolean')]
    private bool $allowed = true;

    #[ORM\Column(type: 'boolean')]
    private bool $canView = false;

    #[ORM\Column(type: 'boolean')]
    private bool $canCreate = false;

    #[ORM\Column(type: 'boolean')]
    private bool $canEdit = false;

    #[ORM\Column(type: 'boolean')]
    private bool $canDelete = false;

    #[ORM\Column(type: 'boolean')]
    private bool $canStats = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): void
    {
        $this->user = $user;
    }

    public function getPermission(): ?Permission
    {
        return $this->permission;
    }

    public function setPermission(?Permission $permission): void
    {
        $this->permission = $permission;
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function setAllowed(bool $allowed): void
    {
        $this->allowed = $allowed;
    }

    public function isCanView(): bool
    {
        return $this->canView;
    }

    public function setCanView(bool $canView): void
    {
        $this->canView = $canView;
    }

    public function isCanCreate(): bool
    {
        return $this->canCreate;
    }

    public function setCanCreate(bool $canCreate): void
    {
        $this->canCreate = $canCreate;
    }

    public function isCanEdit(): bool
    {
        return $this->canEdit;
    }

    public function setCanEdit(bool $canEdit): void
    {
        $this->canEdit = $canEdit;
    }

    public function isCanDelete(): bool
    {
        return $this->canDelete;
    }

    public function setCanDelete(bool $canDelete): void
    {
        $this->canDelete = $canDelete;
    }

    public function isCanStats(): bool
    {
        return $this->canStats;
    }

    public function setCanStats(bool $canStats): void
    {
        $this->canStats = $canStats;
    }
}
