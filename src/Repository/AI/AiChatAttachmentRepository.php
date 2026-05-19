<?php

declare(strict_types=1);

namespace App\Repository\AI;

use App\Entity\AI\AiChatAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiChatAttachment>
 */
class AiChatAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiChatAttachment::class);
    }
}
