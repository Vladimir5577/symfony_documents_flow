<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'permission')]
#[ORM\UniqueConstraint(name: 'uniq_permission_name', columns: ['name'])]
class Permission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Например: ARTICLE_EDIT
    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $label = null;

    public function __construct(string $name = '')
    {
        $this->name = $name;
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $label): self { $this->label = $label; return $this; }
}
