<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'permissions')]
class Permission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 150, unique: true)]
    private string $code = '';

    #[ORM\Column(type: 'string', length: 150)]
    private string $label = '';

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

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
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
