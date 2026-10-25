<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'dashboard_preferences')]
#[ORM\UniqueConstraint(name: 'uniq_dashboard_preferences_user', columns: ['user_id'])]
class DashboardPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $preferences = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPreferences(): array
    {
        return $this->preferences;
    }

    /**
     * @param array<string, mixed> $preferences
     */
    public function setPreferences(array $preferences): void
    {
        $this->preferences = $preferences;
    }
}
