<?php

declare(strict_types=1);

namespace App\Entity\Analytics;

use App\Enum\Analytics\AnalyticsPeriodType;
use App\Repository\Analytics\AnalyticsBoardRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: AnalyticsBoardRepository::class)]
#[ORM\Table(name: 'analytics_boards')]
class AnalyticsBoard
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'period_type', length: 16, enumType: AnalyticsPeriodType::class)]
    private AnalyticsPeriodType $periodType = AnalyticsPeriodType::Weekly;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, AnalyticsBoardVersion> */
    #[ORM\OneToMany(targetEntity: AnalyticsBoardVersion::class, mappedBy: 'board', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['versionNumber' => 'ASC'])]
    private Collection $boardVersions;

    public function __construct()
    {
        $this->boardVersions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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

    /** @return Collection<int, AnalyticsBoardVersion> */
    public function getBoardVersions(): Collection
    {
        return $this->boardVersions;
    }

    public function addBoardVersion(AnalyticsBoardVersion $version): static
    {
        if (!$this->boardVersions->contains($version)) {
            $this->boardVersions->add($version);
            $version->setBoard($this);
        }

        return $this;
    }

    public function removeBoardVersion(AnalyticsBoardVersion $version): static
    {
        $this->boardVersions->removeElement($version);

        return $this;
    }

    public function getPeriodType(): AnalyticsPeriodType
    {
        return $this->periodType;
    }

    public function setPeriodType(AnalyticsPeriodType $periodType): static
    {
        $this->periodType = $periodType;

        return $this;
    }
}
