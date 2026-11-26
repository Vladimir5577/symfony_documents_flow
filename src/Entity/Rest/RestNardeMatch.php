<?php

declare(strict_types=1);

namespace App\Entity\Rest;

use App\Entity\User\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'rest_narde_match')]
#[ORM\Index(name: 'rest_narde_match_queue_idx', columns: ['mode', 'status', 'updated_at'])]
#[ORM\Index(name: 'rest_narde_match_host_idx', columns: ['host_id', 'updated_at'])]
#[ORM\Index(name: 'rest_narde_match_guest_idx', columns: ['guest_id', 'updated_at'])]
final class RestNardeMatch
{
    #[ORM\Id]
    #[ORM\Column(length: 8)]
    private string $code;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $host;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $guest = null;

    #[ORM\Column(length: 10)]
    private string $mode;

    #[ORM\Column(length: 10)]
    private string $status;

    #[ORM\Column(type: 'jsonb')]
    private array $state;

    #[ORM\Column]
    private int $version;

    #[ORM\Column(name: 'updated_at', columnDefinition: 'TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL')]
    private \DateTimeImmutable $updatedAt;
}
