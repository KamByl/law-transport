<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sms_logs')]
class SmsLog
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

    #[ORM\Column(type: 'string', length: 50)]
    private string $recipient = '';

    #[ORM\Column(type: 'text')]
    private string $message = '';

    #[ORM\Column(type: 'boolean')]
    private bool $success = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $smsapiId = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $deliveryStatus = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $deliveryError = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $deliveryPayload = null;

    #[ORM\Column(type: 'string', length: 80)]
    private string $context = '';

    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $sentAt = null;

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

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function setRecipient(string $recipient): void
    {
        $this->recipient = $recipient;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function setSuccess(bool $success): void
    {
        $this->success = $success;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): void
    {
        $this->error = $error;
    }

    public function getSmsapiId(): ?string
    {
        return $this->smsapiId;
    }

    public function setSmsapiId(?string $smsapiId): void
    {
        $this->smsapiId = $smsapiId;
    }

    public function getDeliveryStatus(): ?string
    {
        return $this->deliveryStatus;
    }

    public function setDeliveryStatus(?string $deliveryStatus): void
    {
        $this->deliveryStatus = $deliveryStatus;
    }

    public function getDeliveryError(): ?string
    {
        return $this->deliveryError;
    }

    public function setDeliveryError(?string $deliveryError): void
    {
        $this->deliveryError = $deliveryError;
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function setDeliveredAt(?\DateTimeImmutable $deliveredAt): void
    {
        $this->deliveredAt = $deliveredAt;
    }

    public function getDeliveryPayload(): ?string
    {
        return $this->deliveryPayload;
    }

    public function setDeliveryPayload(?string $deliveryPayload): void
    {
        $this->deliveryPayload = $deliveryPayload;
    }

    public function getContext(): string
    {
        return $this->context;
    }

    public function setContext(string $context): void
    {
        $this->context = $context;
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

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(\DateTimeImmutable $sentAt): void
    {
        $this->sentAt = $sentAt;
    }
}
