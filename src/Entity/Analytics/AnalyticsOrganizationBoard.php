<?php

declare(strict_types=1);

namespace App\Entity\Analytics;

use App\Entity\Organization\AbstractOrganization;
use App\Repository\Analytics\AnalyticsOrganizationBoardRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AnalyticsOrganizationBoardRepository::class)]
#[ORM\Table(name: 'analytics_organization_boards')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_organization_boards', columns: ['organization_id', 'board_id'])]
class AnalyticsOrganizationBoard
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AbstractOrganization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AbstractOrganization $organization = null;

    #[ORM\ManyToOne(targetEntity: AnalyticsBoard::class)]
    #[ORM\JoinColumn(name: 'board_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?AnalyticsBoard $board = null;

    #[ORM\Column(name: 'is_required', options: ['default' => false])]
    private bool $isRequired = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): ?AbstractOrganization
    {
        return $this->organization;
    }

    public function setOrganization(?AbstractOrganization $organization): static
    {
        $this->organization = $organization;

        return $this;
    }

    public function getBoard(): ?AnalyticsBoard
    {
        return $this->board;
    }

    public function setBoard(?AnalyticsBoard $board): static
    {
        $this->board = $board;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function setIsRequired(bool $isRequired): static
    {
        $this->isRequired = $isRequired;

        return $this;
    }
}
