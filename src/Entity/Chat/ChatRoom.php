<?php

namespace App\Entity\Chat;

use App\Entity\Organization\Department;
use App\Enum\Chat\ChatRoomType;
use App\Repository\Chat\ChatRoomRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: ChatRoomRepository::class)]
#[ORM\Table(name: 'chat_room')]
#[ORM\UniqueConstraint(name: 'UNIQ_CHAT_ROOM_ORGANIZATION_TYPE', columns: ['organization_id', 'type'])]
class ChatRoom
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, enumType: ChatRoomType::class, nullable: false)]
    private ChatRoomType $type;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: true)]
    private ?Department $organization = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ChatRoomType
    {
        return $this->type;
    }

    public function setType(ChatRoomType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getOrganization(): ?Department
    {
        return $this->organization;
    }

    public function setOrganization(?Department $organization): static
    {
        $this->organization = $organization;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
