<?php

namespace App\Entity\Kanban;

use App\Enum\KanbanColumnColor;
use App\Repository\Kanban\KanbanColumnRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: KanbanColumnRepository::class)]
#[ORM\Table(name: 'kanban_column')]
class KanbanColumn
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(length: 30, enumType: KanbanColumnColor::class, options: ['default' => 'bg-primary'])]
    private KanbanColumnColor $headerColor = KanbanColumnColor::BG_PRIMARY;

    #[ORM\Column(type: 'float')]
    private float $position = 0.0;

    #[ORM\ManyToOne(targetEntity: KanbanBoard::class, inversedBy: 'columns')]
    #[ORM\JoinColumn(name: 'board_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private KanbanBoard $board;

    /** @var Collection<int, KanbanCard> */
    #[ORM\OneToMany(mappedBy: 'column', targetEntity: KanbanCard::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getHeaderColor(): KanbanColumnColor
    {
        return $this->headerColor;
    }

    public function setHeaderColor(KanbanColumnColor $headerColor): static
    {
        $this->headerColor = $headerColor;
        return $this;
    }

    public function getPosition(): float
    {
        return $this->position;
    }

    public function setPosition(float $position): static
    {
        $this->position = $position;
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

    public function addCard(KanbanCard $card): static
    {
        if (!$this->cards->contains($card)) {
            $this->cards->add($card);
            $card->setColumn($this);
        }
        return $this;
    }
}
