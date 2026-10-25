<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 150, unique: true)]
    private string $email = '';

    #[ORM\Column(type: 'string', length: 255)]
    private string $passwordHash = '';

    #[ORM\Column(type: 'string', length: 120)]
    private string $firstName = '';

    #[ORM\Column(type: 'string', length: 120)]
    private string $lastName = '';

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $canImpersonate = false;

    #[ORM\ManyToMany(targetEntity: Group::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'user_groups')]
    private Collection $groups;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserPermission::class, cascade: ['persist', 'remove'])]
    private Collection $userPermissions;

    public function __construct()
    {
        $this->groups = new ArrayCollection();
        $this->userPermissions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): void
    {
        $this->firstName = $firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): void
    {
        $this->lastName = $lastName;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function canImpersonate(): bool
    {
        return $this->canImpersonate;
    }

    public function setCanImpersonate(bool $canImpersonate): void
    {
        $this->canImpersonate = $canImpersonate;
    }

    /**
     * @return Collection<int, Group>
     */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(Group $group): void
    {
        if (!$this->groups->contains($group)) {
            $this->groups->add($group);
        }
    }

    public function removeGroup(Group $group): void
    {
        $this->groups->removeElement($group);
    }

    /**
     * @return Collection<int, UserPermission>
     */
    public function getUserPermissions(): Collection
    {
        return $this->userPermissions;
    }

    public function addUserPermission(UserPermission $permission): void
    {
        if (!$this->userPermissions->contains($permission)) {
            $this->userPermissions->add($permission);
            $permission->setUser($this);
        }
    }

    public function removeUserPermission(UserPermission $permission): void
    {
        if ($this->userPermissions->removeElement($permission)) {
            if ($permission->getUser() === $this) {
                $permission->setUser(null);
            }
        }
    }
}
