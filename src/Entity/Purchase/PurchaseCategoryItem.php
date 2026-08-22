<?php

namespace App\Entity\Purchase;

use App\Repository\Purchase\PurchaseCategoryItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Номенклатурная позиция категории (Канцелярия → бумага, ручки…).
 * Подсказка-автокомплит для позиций заявки; позиции заявки хранят имя строкой, без FK сюда.
 */
#[ORM\Entity(repositoryClass: PurchaseCategoryItemRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_purchase_category_item', columns: ['category_id', 'name'])]
class PurchaseCategoryItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseCategory::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseCategory $category = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Название позиции обязательно для заполнения.')]
    #[Assert\Length(max: 255, maxMessage: 'Название позиции не должно превышать {{ limit }} символов.')]
    private ?string $name = null;

    /** Позиция выведена из оборота: в подсказках формы не предлагается. */
    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    /** Ключ картинки позиции в бакете purchase; наружу отдаётся только imgproxy-ссылкой. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageKey = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): ?PurchaseCategory
    {
        return $this->category;
    }

    public function setCategory(?PurchaseCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /**
     * Предлагать ли позицию в форме заявки.
     *
     * Выключенная категория гасит свои позиции сама — их собственный флаг при этом
     * не трогаем: включат категорию обратно, и всё вернётся как было.
     */
    public function isAvailable(): bool
    {
        return $this->active && ($this->category?->isActive() ?? false);
    }

    public function getImageKey(): ?string
    {
        return $this->imageKey;
    }

    public function setImageKey(?string $imageKey): static
    {
        $this->imageKey = $imageKey;

        return $this;
    }
}
