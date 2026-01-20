<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_LOGIN', fields: ['login'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // 1) lastname
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $lastname = null;

    // 2) firstname
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $firstname = null;

    // 3) patronymic
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $patronymic = null;

    // 4) boss (self-reference)
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'boss_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $boss = null;

    // 5) phone
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 50)]
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
    private ?string $password = null;

    // 8) is_active
    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    /** @var Collection<int, Role> */
    #[ORM\ManyToMany(targetEntity: Role::class)]
    #[ORM\JoinTable(name: 'user_role')]
    private Collection $rolesRel;

    public function __construct()
    {
        $this->rolesRel = new ArrayCollection();
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

        foreach ($this->rolesRel as $role) {
            $names[] = $role->getName();
        }

        return array_values(array_unique($names));
    }

    /** @return Collection<int, Role> */
    public function getRolesRel(): Collection { return $this->rolesRel; }

    public function addRoleEntity(Role $role): self
    {
        if (!$this->rolesRel->contains($role)) {
            $this->rolesRel->add($role);
        }
        return $this;
    }
}
