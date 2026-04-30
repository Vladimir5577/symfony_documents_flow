<?php

namespace App\Entity\User;

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

    #[ORM\Column(length: 50)]
    private string $name;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    public function __construct(UserRole|string $name = UserRole::ROLE_USER)
    {
        $this->name = $name instanceof UserRole ? $name->value : $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(UserRole|string $name): self
    {
        $this->name = $name instanceof UserRole ? $name->value : $name;

        return $this;
    }

    public function getRole(): ?UserRole
    {
        return UserRole::tryFrom($this->name);
    }

    public function setRole(UserRole $name): self
    {
        $this->name = $name->value;

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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }
}
