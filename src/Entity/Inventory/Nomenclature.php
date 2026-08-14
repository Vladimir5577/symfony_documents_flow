<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Repository\Inventory\NomenclatureRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Вид товара — справочник, общий на всю компанию.
 *
 * Наименование живёт здесь, а не у каждой единицы: одинаковые мониторы заводили
 * как «монитор Dell» и «Монитор DELL», из-за чего их нельзя было ни найти, ни сосчитать.
 * Количество в справочник не кладём: десять мониторов — это десять строк
 * NomenclatureItem с одним nomenclature_id.
 */
#[ORM\Entity(repositoryClass: NomenclatureRepository::class)]
#[ORM\Table(name: 'inventory_nomenclature')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_nomenclature_name', columns: ['name_lower'])]
class Nomenclature
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    /**
     * Имя в нижнем регистре — существует только ради уникальности без учёта регистра:
     * «Монитор Dell» и «монитор dell» должны быть одним видом, иначе справочник
     * обрастает теми же дублями, ради которых он и заводился.
     *
     * Считает Postgres (GENERATED ALWAYS), приложение сюда не пишет. Функциональный
     * индекс по LOWER(name) дал бы то же самое, но Doctrine его не интроспектирует
     * и сносила бы его при каждом diff.
     */
    #[ORM\Column(
        name: 'name_lower',
        length: 255,
        nullable: true,
        insertable: false,
        updatable: false,
        generated: 'ALWAYS',
        columnDefinition: 'VARCHAR(255) GENERATED ALWAYS AS (LOWER(name)) STORED',
    )]
    private ?string $nameLower = null;

    /**
     * Категория — свойство вида, а не отдельной единицы: разные категории означают
     * разные виды. NULL — «ещё не разобрали», честное «неизвестно»: неразобранный вид
     * доступен только админу организации, ответственный за категорию его не видит.
     */
    #[ORM\ManyToOne(targetEntity: ItemCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?ItemCategory $category = null;

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

    public function getCategory(): ?ItemCategory
    {
        return $this->category;
    }

    public function setCategory(?ItemCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
