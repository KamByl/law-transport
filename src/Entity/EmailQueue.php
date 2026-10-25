<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'email_queue')]
class EmailQueue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $recipient = '';

    #[ORM\Column(type: 'string', length: 255)]
    private string $subject = '';

    #[ORM\Column(type: 'text')]
    private string $body = '';

    /** @var array<int, array{cid: string, filename: string, content_type: string, data: string}> */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $inlineAttachments = null;

    #[ORM\Column(type: 'boolean')]
    private bool $decorateBody = true;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $priority = false;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $bcc = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = 'pending';

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    #[ORM\Column(type: 'integer')]
    private int $maxAttempts = 5;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $availableAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function setRecipient(string $recipient): void
    {
        $this->recipient = $recipient;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): void
    {
        $this->subject = $subject;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    /** @return array<int, array{cid: string, filename: string, content_type: string, data: string}> */
    public function getInlineAttachments(): array
    {
        return $this->inlineAttachments ?? [];
    }

    /** @param array<int, array{cid: string, filename: string, content_type: string, data: string}> $inlineAttachments */
    public function setInlineAttachments(array $inlineAttachments): void
    {
        $this->inlineAttachments = $inlineAttachments;
    }

    public function shouldDecorateBody(): bool
    {
        return $this->decorateBody;
    }

    public function setDecorateBody(bool $decorateBody): void
    {
        $this->decorateBody = $decorateBody;
    }

    public function isPriority(): bool
    {
        return $this->priority;
    }

    public function setPriority(bool $priority): void
    {
        $this->priority = $priority;
    }

    public function getBcc(): ?string
    {
        return $this->bcc;
    }

    public function setBcc(?string $bcc): void
    {
        $this->bcc = $bcc;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): void
    {
        $this->attempts = $attempts;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function setMaxAttempts(int $maxAttempts): void
    {
        $this->maxAttempts = $maxAttempts;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): void
    {
        $this->lastError = $lastError;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getAvailableAt(): ?\DateTimeImmutable
    {
        return $this->availableAt;
    }

    public function setAvailableAt(\DateTimeImmutable $availableAt): void
    {
        $this->availableAt = $availableAt;
    }

    public function getLockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function setLockedAt(?\DateTimeImmutable $lockedAt): void
    {
        $this->lockedAt = $lockedAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): void
    {
        $this->sentAt = $sentAt;
    }
}
