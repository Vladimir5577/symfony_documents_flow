<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_LOGIN', fields: ['login'])]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false, hardDelete: false)]
#[UniqueEntity(
    fields: ['login'],
    message: 'Пользователь с таким логином уже существует.',
    repositoryMethod: 'findOneByLogin',
    ignoreNull: false
)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // organization
    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Организация обязательна для заполнения.')]
    private Organization $organization;


    // 1) lastname
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Фамилия обязательна для заполнения.')]
    #[Assert\Length(max: 50, maxMessage: 'Фамилия не должна превышать {{ limit }} символов.')]
    private ?string $lastname = null;

    // 2) firstname
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Имя обязательно для заполнения.')]
    #[Assert\Length(max: 50, maxMessage: 'Имя не должно превышать {{ limit }} символов.')]
    private ?string $firstname = null;

    // 3) patronymic
    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50, maxMessage: 'Отчество не должно превышать {{ limit }} символов.')]
    private ?string $patronymic = null;

    // 4) boss (self-reference)
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'boss_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $boss = null;

    // 5) phone
    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30, maxMessage: 'Телефон не должен превышать {{ limit }} символов.')]
    private ?string $phone = null;

    #[ORM\Column(name: 'birth_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $birthDay = null;

    // 6) email
    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Email(message: 'Email имеет неверный формат.')]
    #[Assert\Length(max: 50, maxMessage: 'Email не должен превышать {{ limit }} символов.')]
    private ?string $email = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Логин обязателен для заполнения.')]
    #[Assert\Length(
        min: 1,
        max: 50,
        minMessage: 'Логин не может быть пустым.',
        maxMessage: 'Логин не должен превышать {{ limit }} символов.'
    )]
    private ?string $login = null;

//    /**
//     * @var list<string> The user roles
//     */
//    #[ORM\Column]
//    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    #[Assert\NotBlank(message: 'Пароль обязателен для заполнения.')]
    private ?string $password = null;

    // 8) is_active
    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    // 9) created_at
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    // 10) created_by (self-reference)
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $createdBy = null;

    // 11) updated_at
    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    // 12) deleted_at (soft delete)
    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    // 13) updated_by (self-reference)
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $updatedBy = null;

    /** @var Collection<int, UserRole> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserRole::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $userRoles;

    public function __construct()
    {
        $this->userRoles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLogin(): ?string
    {
        return $this->login;
    }

    public function setLogin(string $login): static
    {
        $this->login = $login;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->login;
    }

//    public function getRoles(): array
//    {
//        $roles = $this->roles;
//        $roles[] = 'ROLE_USER';
//
//        return array_unique($roles);
//    }
//
//    /**
//     * @param list<string> $roles
//     */
//    public function setRoles(array $roles): static
//    {
//        $this->roles = $roles;
//
//        return $this;
//    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(?string $lastname): static
    {
        $this->lastname = $lastname;

        return $this;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(?string $firstname): static
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getPatronymic(): ?string
    {
        return $this->patronymic;
    }

    public function setPatronymic(?string $patronymic): static
    {
        $this->patronymic = $patronymic;

        return $this;
    }

    public function getBoss(): ?self
    {
        return $this->boss;
    }

    public function setBoss(?self $boss): static
    {
        // Защита от очевидной ошибки: пользователь не может быть начальником сам себе
        if ($boss !== null && $boss->getId() !== null && $this->getId() !== null && $boss->getId() === $this->getId()) {
            throw new \InvalidArgumentException('User cannot be their own boss.');
        }

        $this->boss = $boss;

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

    public function getBirthDay(): ?\DateTimeImmutable
    {
        return $this->birthDay;
    }

    public function setBirthDay(?\DateTimeImmutable $birthDay): static
    {
        $this->birthDay = $birthDay;

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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }

    /**
     * Symfony Security требует массив строк.
     * Мы строим его из таблицы role.
     */
    public function getRoles(): array
    {
        $names = ['ROLE_USER'];

        foreach ($this->userRoles as $userRole) {
            $role = $userRole->getRole();
            if ($role) {
                $names[] = $role->getName();
            }
        }

        return array_values(array_unique($names));
    }

    /** @return Collection<int, UserRole> */
    public function getRolesRel(): Collection
    {
        return $this->userRoles;
    }

    public function addRoleEntity(Role $role): self
    {
        foreach ($this->userRoles as $userRole) {
            if ($userRole->getRole() === $role) {
                return $this;
            }
        }

        $this->userRoles->add(new UserRole($this, $role));
        return $this;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function setOrganization(Organization $organization): static
    {
        $this->organization = $organization;

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

    public function getCreatedBy(): ?self
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?self $createdBy): static
    {
        $this->createdBy = $createdBy;

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

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function getUpdatedBy(): ?self
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?self $updatedBy): static
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }
}
