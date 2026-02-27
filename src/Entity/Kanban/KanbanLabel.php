<?php

namespace App\Entity\Kanban;

use App\Enum\KanbanColumnColor;
use App\Repository\Kanban\KanbanLabelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: KanbanLabelRepository::class)]
#[ORM\Table(name: 'kanban_label')]
class KanbanLabel
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 30, enumType: KanbanColumnColor::class)]
    private KanbanColumnColor $color = KanbanColumnColor::BG_PRIMARY;

    #[ORM\ManyToOne(targetEntity: KanbanBoard::class, inversedBy: 'labels')]
    #[ORM\JoinColumn(name: 'board_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private KanbanBoard $board;

    /** @var Collection<int, KanbanCard> */
    #[ORM\ManyToMany(targetEntity: KanbanCard::class, mappedBy: 'labels')]
    private Collection $cards;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->cards = new ArrayCollection();
    }

    public function getId(): Uuid
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

    public function getColor(): KanbanColumnColor
    {
        return $this->color;
    }

    public function setColor(KanbanColumnColor $color): static
    {
        $this->color = $color;
        return $this;
    }

    public function getBoard(): KanbanBoard
    {
        return $this->board;
    }

    public function setBoard(KanbanBoard $board): static
    {
        $this->board = $board;
        return $this;
    }

    /** @return Collection<int, KanbanCard> */
    public function getCards(): Collection
    {
        return $this->cards;
    }
}
