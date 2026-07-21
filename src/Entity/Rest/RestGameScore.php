<?php

declare(strict_types=1);

namespace App\Entity\Rest;

use App\Entity\User\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'rest_game_score')]
#[ORM\UniqueConstraint(name: 'uniq_rest_game_score_user_game', columns: ['user_id', 'game'])]
class RestGameScore
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 50)]
    private string $game;

    #[ORM\Column]
    private int $score = 0;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, string $game, int $score)
    {
        $this->user = $user;
        $this->game = $game;
        $this->score = $score;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getGame(): string
    {
        return $this->game;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function setScore(int $score): void
    {
        $this->score = $score;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
