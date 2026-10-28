<?php

declare(strict_types=1);

namespace App\Entity\FileGroup;

use App\Entity\User\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'file_group')]
#[ORM\Index(name: 'idx_file_group_owner', columns: ['owner_id'])]
class FileGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $owner;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'file_group_member')]
    #[ORM\JoinColumn(name: 'group_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $members;

    /** @var Collection<int, FileGroupFolder> */
    #[ORM\OneToMany(mappedBy: 'group', targetEntity: FileGroupFolder::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $folders;

    /** @var Collection<int, FileGroupFile> */
    #[ORM\OneToMany(mappedBy: 'group', targetEntity: FileGroupFile::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $files;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $owner, string $name)
    {
        $this->owner = $owner;
        $this->name = $name;
        $this->members = new ArrayCollection();
        $this->folders = new ArrayCollection();
        $this->files = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    /** @return Collection<int, User> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(User $user): void
    {
        if (!$this->members->contains($user)) {
            $this->members->add($user);
        }
    }

    public function removeMember(User $user): void
    {
        $this->members->removeElement($user);
    }

    /** @return Collection<int, FileGroupFolder> */
    public function getFolders(): Collection
    {
        return $this->folders;
    }

    /** @return Collection<int, FileGroupFile> */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
