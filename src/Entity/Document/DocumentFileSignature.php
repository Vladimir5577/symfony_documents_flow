<?php

declare(strict_types=1);

namespace App\Entity\Document;

use App\Entity\User\User;
use App\Repository\Document\DocumentFileSignatureRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Штамп «Подписано» на PDF-вложении. Сам файл не меняется: PDF со штампами
 * собирается при скачивании, см. DocumentFileSignatureService.
 *
 * x, y — центр штампа в долях страницы (0..1) от верхнего левого угла.
 */
#[ORM\Entity(repositoryClass: DocumentFileSignatureRepository::class)]
#[ORM\Table(name: 'document_file_signature')]
#[ORM\UniqueConstraint(name: 'uniq_document_file_signature_file_user', columns: ['file_id', 'user_id'])]
class DocumentFileSignature
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: File::class)]
    #[ORM\JoinColumn(name: 'file_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private File $file;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    #[ORM\Column(type: Types::INTEGER)]
    private int $page;

    #[ORM\Column(type: Types::FLOAT)]
    private float $x;

    #[ORM\Column(type: Types::FLOAT)]
    private float $y;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(File $file, User $user)
    {
        $this->file = $file;
        $this->user = $user;
    }

    /** Повторная подпись переносит штамп и обновляет дату. */
    public function place(int $page, float $x, float $y): void
    {
        $this->page = $page;
        $this->x = $x;
        $this->y = $y;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFile(): File
    {
        return $this->file;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getX(): float
    {
        return $this->x;
    }

    public function getY(): float
    {
        return $this->y;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
