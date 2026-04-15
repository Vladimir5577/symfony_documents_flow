<?php

namespace App\Entity\User;

use App\Entity\Organization\AbstractOrganization;
use App\Repository\User\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

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
#[Vich\Uploadable]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // organization (может быть Organization, Filial или Department)
    #[ORM\ManyToOne(targetEntity: AbstractOrganization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?AbstractOrganization $organization = null;


    // 1) lastname
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Фамилия обязательна для заполнения.')]
    #[Assert\Length(max: 50, maxMessage: 'Фамилия не должна превышать {{ limit }} символов.')]
    #[Assert\Regex(
        pattern: '/^[\p{L}\s\-]+$/u',
        message: 'Фамилия может содержать только буквы, пробелы и дефис. Цифры и спецсимволы не допускаются.'
    )]
    private ?string $lastname = null;

    // 2) firstname
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Имя обязательно для заполнения.')]
    #[Assert\Length(max: 50, maxMessage: 'Имя не должно превышать {{ limit }} символов.')]
    #[Assert\Regex(
        pattern: '/^[\p{L}\s\-]+$/u',
        message: 'Имя может содержать только буквы, пробелы и дефис. Цифры и спецсимволы не допускаются.'
    )]
    private ?string $firstname = null;

    // 3) patronymic
    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50, maxMessage: 'Отчество не должно превышать {{ limit }} символов.')]
    #[Assert\Regex(
        pattern: '/^[\p{L}\s\-]*$/u',
        message: 'Отчество может содержать только буквы, пробелы и дефис. Цифры и спецсимволы не допускаются.'
    )]
    private ?string $patronymic = null;

    // 4) boss (self-reference)
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'boss_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $boss = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: Worker::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?Worker $worker = null;

    // 5) phone
    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30, maxMessage: 'Телефон не должен превышать {{ limit }} символов.')]
    #[Assert\Regex(
        pattern: '/^(\+7\s*\(\d{3}\)\s*\d{3}\s*\d{2}\s*\d{2})?$/',
        message: 'Телефон должен быть в формате: +7 (123) 123 12 12.'
    )]
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

    // work_with_documents
    #[ORM\Column(options: ['default' => false])]
    private bool $workWithDocuments = false;

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

    #[ORM\Column(name: 'last_seen_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    // 13) updated_by (self-reference)
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $updatedBy = null;

    /** @var Collection<int, UserRole> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserRole::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $userRoles;

    #[Vich\UploadableField(mapping: 'user_avatar', fileNameProperty: 'avatarName')]
    private ?SymfonyFile $avatarFile = null;

    #[ORM\Column(name: 'avatar_name', type: Types::STRING, length: 255, nullable: true)]
    private ?string $avatarName = null;

    /** @var Collection<int, UserFile> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserFile::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $files;

    public function __construct()
    {
        $this->userRoles = new ArrayCollection();
        $this->files = new ArrayCollection();
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

    public function getWorker(): ?Worker
    {
        return $this->worker;
    }

    public function setWorker(?Worker $worker): static
    {
        if ($worker !== null) {
            $worker->setUser($this);
        }
        $this->worker = $worker;

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

    public function isWorkWithDocuments(): bool
    {
        return $this->workWithDocuments;
    }

    public function setWorkWithDocuments(bool $workWithDocuments): static
    {
        $this->workWithDocuments = $workWithDocuments;

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
     * Symfony Security требует массив строк — только из связи user_roles.
     * Без записей возвращаем ROLE_USER (минимальная роль).
     * Расширенные права (MODERATOR и т.д.) не дублируют ROLE_USER в массиве:
     * для проверок доступа используется role_hierarchy в security.yaml.
     */
    public function getRoles(): array
    {
        $names = [];
        foreach ($this->userRoles as $userRole) {
            $role = $userRole->getRole();
            if ($role) {
                $names[] = $role->getName();
            }
        }

        if ($names === []) {
            return ['ROLE_USER'];
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

    public function getOrganization(): ?AbstractOrganization
    {
        return $this->organization;
    }

    public function setOrganization(?AbstractOrganization $organization): static
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

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): static
    {
        $this->lastSeenAt = $lastSeenAt;

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

    public function getAvatarFile(): ?SymfonyFile
    {
        return $this->avatarFile;
    }

    public function setAvatarFile(?SymfonyFile $avatarFile = null): static
    {
        $this->avatarFile = $avatarFile;

        return $this;
    }

    public function getAvatarName(): ?string
    {
        return $this->avatarName;
    }

    public function setAvatarName(?string $avatarName): static
    {
        $this->avatarName = $avatarName;

        return $this;
    }

    /** @return Collection<int, UserFile> */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function addFile(UserFile $file): static
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
            $file->setUser($this);
        }

        return $this;
    }

    public function removeFile(UserFile $file): static
    {
        if ($this->files->removeElement($file)) {
            if ($file->getUser() === $this) {
                $file->setUser(null);
            }
        }

        return $this;
    }
}
