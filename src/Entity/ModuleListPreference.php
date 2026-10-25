<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'module_list_preferences')]
#[ORM\UniqueConstraint(name: 'uniq_module_list_preferences_user_module', columns: ['user_id', 'module_key'])]
class ModuleListPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'module_key', type: 'string', length: 191)]
    private string $moduleKey = '';

    /**
     * @var array<string, scalar|null>
     */
    #[ORM\Column(type: 'json')]
    private array $state = [];

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

    public function getModuleKey(): string
    {
        return $this->moduleKey;
    }

    public function setModuleKey(string $moduleKey): void
    {
        $this->moduleKey = $moduleKey;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getState(): array
    {
        return $this->state;
    }

    /**
     * @param array<string, scalar|null> $state
     */
    public function setState(array $state): void
    {
        $this->state = $state;
    }
}
