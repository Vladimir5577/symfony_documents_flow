<?php

namespace App\Entity;

use App\Enum\UserRole;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'role')]
#[ORM\UniqueConstraint(name: 'uniq_role_name', columns: ['name'])]
class Role
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, enumType: UserRole::class)]
    private UserRole $name;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $label = null;

    public function __construct(UserRole|string $name = UserRole::ROLE_USER)
    {
        $this->name = \is_string($name) ? UserRole::from($name) : $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name->value;
    }

    public function setName(UserRole|string $name): self
    {
        $this->name = \is_string($name) ? UserRole::from($name) : $name;

        return $this;
    }

    public function getRole(): UserRole
    {
        return $this->name;
    }

    public function setRole(UserRole $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        $this->label = $label;

        return $this;
    }
}
