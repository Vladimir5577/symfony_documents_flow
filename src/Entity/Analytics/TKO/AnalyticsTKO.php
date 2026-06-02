<?php

declare(strict_types=1);

namespace App\Entity\Analytics\TKO;

use App\Entity\Polygon\Polygon;
use App\Entity\User\User;
use App\Repository\Analytics\TKO\AnalyticsTKORepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Суточная запись отчётности по полигону ТКО.
 * Одна строка = один полигон за одну дату («длинный» формат: дни не в колонках, а в строках).
 */
#[ORM\Entity(repositoryClass: AnalyticsTKORepository::class)]
#[ORM\Table(name: 'analytics_tko')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_tko_polygon_date', columns: ['polygon_id', 'report_date'])]
#[ORM\Index(name: 'idx_analytics_tko_report_date', columns: ['report_date'])]
#[ORM\Index(name: 'idx_analytics_tko_polygon_id', columns: ['polygon_id'])]
class AnalyticsTKO
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Polygon::class)]
    #[ORM\JoinColumn(name: 'polygon_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?Polygon $polygon = null;

    #[ORM\Column(name: 'report_date', type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $reportDate = null;

    /** Мусоровозы — объём, м³. */
    #[ORM\Column(name: 'garbage_trucks_volume', type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $garbageTrucksVolume = null;

    /** Вес ТКО мусоровозы, т. */
    #[ORM\Column(name: 'garbage_trucks_weight', type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $garbageTrucksWeight = null;

    /** Контейнеры — объём, м³. */
    #[ORM\Column(name: 'containers_volume', type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $containersVolume = null;

    /** Ломовозы — объём, м³. */
    #[ORM\Column(name: 'scrap_trucks_volume', type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $scrapTrucksVolume = null;

    /** Вес ТКО контейнеры + ломовозы, т. */
    #[ORM\Column(name: 'containers_scrap_weight', type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $containersScrapWeight = null;

    /** Растительные отходы — объём, м³. */
    #[ORM\Column(name: 'vegetation_volume', type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $vegetationVolume = null;

    /** Строительные отходы — объём, м³. */
    #[ORM\Column(name: 'construction_volume', type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $constructionVolume = null;

    /** Терминал — объём, м³. */
    #[ORM\Column(name: 'terminal_volume', type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $terminalVolume = null;

    /** Субботники — объём, м³. */
    #[ORM\Column(name: 'subbotniki_volume', type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $subbotnikiVolume = null;

    /** Работа бульдозера — текстовая отметка («работал», «Д-12» и т.п.). */
    #[ORM\Column(name: 'bulldozer_work', type: Types::STRING, length: 255, nullable: true)]
    private ?string $bulldozerWork = null;

    /** Работа техники — текстовая отметка («работал», «АМКАДОР» и т.п.). */
    #[ORM\Column(name: 'equipment_work', type: Types::STRING, length: 255, nullable: true)]
    private ?string $equipmentWork = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPolygon(): ?Polygon
    {
        return $this->polygon;
    }

    public function setPolygon(?Polygon $polygon): static
    {
        $this->polygon = $polygon;

        return $this;
    }

    public function getReportDate(): ?\DateTimeImmutable
    {
        return $this->reportDate;
    }

    public function setReportDate(?\DateTimeImmutable $reportDate): static
    {
        $this->reportDate = $reportDate;

        return $this;
    }

    public function getGarbageTrucksVolume(): ?string
    {
        return $this->garbageTrucksVolume;
    }

    public function setGarbageTrucksVolume(?string $garbageTrucksVolume): static
    {
        $this->garbageTrucksVolume = $garbageTrucksVolume;

        return $this;
    }

    public function getGarbageTrucksWeight(): ?string
    {
        return $this->garbageTrucksWeight;
    }

    public function setGarbageTrucksWeight(?string $garbageTrucksWeight): static
    {
        $this->garbageTrucksWeight = $garbageTrucksWeight;

        return $this;
    }

    public function getContainersVolume(): ?string
    {
        return $this->containersVolume;
    }

    public function setContainersVolume(?string $containersVolume): static
    {
        $this->containersVolume = $containersVolume;

        return $this;
    }

    public function getScrapTrucksVolume(): ?string
    {
        return $this->scrapTrucksVolume;
    }

    public function setScrapTrucksVolume(?string $scrapTrucksVolume): static
    {
        $this->scrapTrucksVolume = $scrapTrucksVolume;

        return $this;
    }

    public function getContainersScrapWeight(): ?string
    {
        return $this->containersScrapWeight;
    }

    public function setContainersScrapWeight(?string $containersScrapWeight): static
    {
        $this->containersScrapWeight = $containersScrapWeight;

        return $this;
    }

    public function getVegetationVolume(): ?string
    {
        return $this->vegetationVolume;
    }

    public function setVegetationVolume(?string $vegetationVolume): static
    {
        $this->vegetationVolume = $vegetationVolume;

        return $this;
    }

    public function getConstructionVolume(): ?string
    {
        return $this->constructionVolume;
    }

    public function setConstructionVolume(?string $constructionVolume): static
    {
        $this->constructionVolume = $constructionVolume;

        return $this;
    }

    public function getTerminalVolume(): ?string
    {
        return $this->terminalVolume;
    }

    public function setTerminalVolume(?string $terminalVolume): static
    {
        $this->terminalVolume = $terminalVolume;

        return $this;
    }

    public function getSubbotnikiVolume(): ?string
    {
        return $this->subbotnikiVolume;
    }

    public function setSubbotnikiVolume(?string $subbotnikiVolume): static
    {
        $this->subbotnikiVolume = $subbotnikiVolume;

        return $this;
    }

    public function getBulldozerWork(): ?string
    {
        return $this->bulldozerWork;
    }

    public function setBulldozerWork(?string $bulldozerWork): static
    {
        $this->bulldozerWork = $bulldozerWork;

        return $this;
    }

    public function getEquipmentWork(): ?string
    {
        return $this->equipmentWork;
    }

    public function setEquipmentWork(?string $equipmentWork): static
    {
        $this->equipmentWork = $equipmentWork;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
