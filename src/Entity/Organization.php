<?php

namespace App\Entity;

use App\Repository\OrganizationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false, hardDelete: false)]
#[UniqueEntity(
    fields: ['name'],
    message: 'Организация с таким названием уже существует.',
    repositoryMethod: 'findOneByName',
    ignoreNull: false
)]
class Organization
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Название организации обязательно для заполнения.')]
    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: 'Название организации должно содержать минимум {{ limit }} символа.',
        maxMessage: 'Название организации не должно превышать {{ limit }} символов.'
    )]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(
        max: 255,
        maxMessage: 'Адрес не должен превышать {{ limit }} символов.'
    )]
    private ?string $address = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(
        max: 100,
        maxMessage: 'Телефон не должен превышать {{ limit }} символов.'
    )]
    private ?string $phone = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Email(message: 'Email имеет неверный формат.')]
    #[Assert\Length(
        max: 100,
        maxMessage: 'Email не должен превышать {{ limit }} символов.'
    )]
    #[Assert\NotBlank(allowNull: true)]
    private ?string $email = null;

    // parent (self-reference)
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'childOrganizations')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    private Collection $childOrganizations;

    /** @var Collection<int, Department> */
    #[ORM\OneToMany(mappedBy: 'organization', targetEntity: Department::class)]
    private Collection $departments;

    // created_at
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    // updated_at
    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    // deleted_at (soft delete)
    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $this->childOrganizations = new ArrayCollection();
        $this->departments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, self> */
    public function getChildOrganizations(): Collection
    {
        return $this->childOrganizations;
    }

    public function addChildOrganization(self $childOrganization): static
    {
        if (!$this->childOrganizations->contains($childOrganization)) {
            $this->childOrganizations->add($childOrganization);
            $childOrganization->setParent($this);
        }

        return $this;
    }

    public function removeChildOrganization(self $childOrganization): static
    {
        if ($this->childOrganizations->removeElement($childOrganization)) {
            if ($childOrganization->getParent() === $this) {
                $childOrganization->setParent(null);
            }
        }

        return $this;
    }

    /** @return Collection<int, Department> */
    public function getDepartments(): Collection
    {
        return $this->departments;
    }

    public function addDepartment(Department $department): static
    {
        if (!$this->departments->contains($department)) {
            $this->departments->add($department);
            $department->setOrganization($this);
        }

        return $this;
    }

    public function removeDepartment(Department $department): static
    {
        $this->departments->removeElement($department);

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }
}
