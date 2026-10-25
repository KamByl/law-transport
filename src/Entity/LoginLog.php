<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'login_logs')]
class LoginLog
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

    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $loggedAt = null;

    #[ORM\Column(type: 'boolean')]
    private bool $success = false;

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

    public function getLoggedAt(): ?\DateTimeImmutable
    {
        return $this->loggedAt;
    }

    public function setLoggedAt(\DateTimeImmutable $loggedAt): void
    {
        $this->loggedAt = $loggedAt;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function setSuccess(bool $success): void
    {
        $this->success = $success;
    }
}
