<?php

declare(strict_types=1);

namespace App\Entity\FileGroup;

use App\Entity\User\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'file_group_file')]
#[ORM\Index(name: 'idx_file_group_file_group', columns: ['group_id'])]
#[ORM\Index(name: 'idx_file_group_file_folder', columns: ['folder_id'])]
#[ORM\Index(name: 'idx_file_group_file_uploaded_by', columns: ['uploaded_by_id'])]
class FileGroupFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FileGroup::class, inversedBy: 'files')]
    #[ORM\JoinColumn(name: 'group_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private FileGroup $group;

    #[ORM\ManyToOne(targetEntity: FileGroupFolder::class)]
    #[ORM\JoinColumn(name: 'folder_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?FileGroupFolder $folder;

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 64)]
    private string $storedName;

    #[ORM\Column]
    private int $size;

    /** Кто загрузил. У файла создателя группы пусто: у него и так полная запись. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'file_group_file_writer')]
    #[ORM\JoinColumn(name: 'file_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $writers;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(FileGroup $group, string $originalName, string $storedName, int $size, ?FileGroupFolder $folder)
    {
        $this->group = $group;
        $this->originalName = $originalName;
        $this->storedName = $storedName;
        $this->size = $size;
        $this->folder = $folder;
        $this->writers = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGroup(): FileGroup
    {
        return $this->group;
    }

    public function getFolder(): ?FileGroupFolder
    {
        return $this->folder;
    }

    public function setFolder(?FileGroupFolder $folder): void
    {
        $this->folder = $folder;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getStoredName(): string
    {
        return $this->storedName;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $user): void
    {
        $this->uploadedBy = $user;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): void
    {
        $this->size = $size;
    }

    /** @return Collection<int, User> */
    public function getWriters(): Collection
    {
        return $this->writers;
    }

    public function addWriter(User $user): void
    {
        if (!$this->writers->contains($user)) {
            $this->writers->add($user);
        }
    }

    public function removeWriter(User $user): void
    {
        $this->writers->removeElement($user);
    }
}
