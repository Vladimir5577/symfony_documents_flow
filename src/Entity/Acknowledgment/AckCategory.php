<?php

declare(strict_types=1);

namespace App\Entity\Acknowledgment;

use App\Repository\Acknowledgment\AckCategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Категория раздела «Документы к ознакомлению».
 *
 * Поиска в модуле нет, поэтому категория — единственный способ навигации по
 * реестру, и у документа она обязательна. sort нужен ровно поэтому же: по id
 * список шёл бы в порядке заведения, а не в осмысленном.
 */
#[ORM\Entity(repositoryClass: AckCategoryRepository::class)]
#[ORM\Table(name: 'ack_category')]
#[ORM\UniqueConstraint(name: 'uniq_ack_category_name', columns: ['name'])]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false, hardDelete: false)]
class AckCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Название категории обязательно для заполнения.')]
    #[Assert\Length(max: 255, maxMessage: 'Название категории не должно превышать {{ limit }} символов.')]
    private string $name = '';

    #[ORM\Column(options: ['default' => 0])]
    private int $sort = 0;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

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

    public function getSort(): int
    {
        return $this->sort;
    }

    public function setSort(int $sort): static
    {
        $this->sort = $sort;

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
