<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'module_change_logs')]
class ModuleChangeLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 150)]
    private string $email = '';

    #[ORM\Column(type: 'string', length: 100)]
    private string $moduleKey = '';

    #[ORM\Column(type: 'string', length: 30)]
    private string $action = '';

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $recordId = null;

    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $changedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $changeSet = null;

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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getModuleKey(): string
    {
        return $this->moduleKey;
    }

    public function setModuleKey(string $moduleKey): void
    {
        $this->moduleKey = $moduleKey;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): void
    {
        $this->action = $action;
    }

    public function getRecordId(): ?int
    {
        return $this->recordId;
    }

    public function setRecordId(?int $recordId): void
    {
        $this->recordId = $recordId;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): void
    {
        $this->ipAddress = $ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): void
    {
        $this->userAgent = $userAgent;
    }

    public function getChangedAt(): ?\DateTimeImmutable
    {
        return $this->changedAt;
    }

    public function setChangedAt(\DateTimeImmutable $changedAt): void
    {
        $this->changedAt = $changedAt;
    }

    public function getChangeSet(): ?string
    {
        return $this->changeSet;
    }

    public function setChangeSet(?string $changeSet): void
    {
        $this->changeSet = $changeSet;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getChangeSetData(): array
    {
        if ($this->changeSet === null || $this->changeSet === '') {
            return [];
        }

        $decoded = json_decode($this->changeSet, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }
}
