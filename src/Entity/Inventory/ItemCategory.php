<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Repository\Inventory\ItemCategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: ItemCategoryRepository::class)]
#[ORM\Table(name: 'inventory_category')]
/*
 * Категории вложенные: «Оргтехника › ПК › Мини ПК». Имя уникально внутри родителя,
 * чтобы «Прочее» могло быть и в «Оргтехнике», и в «Мебели». У корневых родителя нет,
 * а NULL-ы PostgreSQL в UNIQUE не сравнивает — для них отдельный партиальный индекс
 * (предикат записан так, как его нормализует Postgres, иначе doctrine diff его пересоздаёт).
 */
#[ORM\UniqueConstraint(name: 'uniq_inventory_category_parent_name', columns: ['parent_id', 'name'])]
#[ORM\UniqueConstraint(
    name: 'uniq_inventory_category_root_name',
    columns: ['name'],
    options: ['where' => '(parent_id IS NULL)'],
)]
class ItemCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private string $name;

    /**
     * NULL — корневая категория. RESTRICT: категорию с подкатегориями удалять нельзя,
     * CategoryController отвечает на это 409 раньше, чем сработает ключ.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?self $parent = null;

    /**
     * Неактивная категория не предлагается при создании товаров, но существующие товары в ней остаются.
     * Действует на всю ветку: см. CategoryTree::isActive.
     */
    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

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

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
