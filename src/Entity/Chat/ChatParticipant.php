<?php

namespace App\Entity\Chat;

use App\Entity\User\User;
use App\Repository\Chat\ChatParticipantRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChatParticipantRepository::class)]
#[ORM\Table(
    indexes: [new ORM\Index(name: 'IDX_CHAT_PARTICIPANT_USER_ID', columns: ['user_id'])],
    uniqueConstraints: [new ORM\UniqueConstraint(name: 'UNIQ_CHAT_PARTICIPANT_ROOM_USER', columns: ['room_id', 'user_id'])]
)]
class ChatParticipant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChatRoom::class)]
    #[ORM\JoinColumn(name: 'room_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ChatRoom $room = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoom(): ?ChatRoom
    {
        return $this->room;
    }

    public function setRoom(?ChatRoom $room): static
    {
        $this->room = $room;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }
}
