<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'error_logs')]
class ErrorLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(type: 'integer')]
    private int $statusCode = 500;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $exceptionClass = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $exceptionMessage = null;

    #[ORM\Column(type: 'string', length: 2048, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(type: 'string', length: 16, nullable: true)]
    private ?string $method = null;

    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $stackTrace = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

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

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setStatusCode(int $statusCode): void
    {
        $this->statusCode = $statusCode;
    }

    public function getExceptionClass(): ?string
    {
        return $this->exceptionClass;
    }

    public function setExceptionClass(?string $exceptionClass): void
    {
        $this->exceptionClass = $exceptionClass;
    }

    public function getExceptionMessage(): ?string
    {
        return $this->exceptionMessage;
    }

    public function setExceptionMessage(?string $exceptionMessage): void
    {
        $this->exceptionMessage = $exceptionMessage;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): void
    {
        $this->url = $url;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    public function setMethod(?string $method): void
    {
        $this->method = $method;
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

    public function getStackTrace(): ?string
    {
        return $this->stackTrace;
    }

    public function setStackTrace(?string $stackTrace): void
    {
        $this->stackTrace = $stackTrace;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }
}
