<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Rest;

use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/rest/block-blast/campaign/{accountId<\d+>}')]
final class BlockBlastCampaignController extends AbstractController
{
    private const LAST_LEVEL = 100;

    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('', name: 'spa_api_rest_block_blast_campaign_read', methods: ['GET'])]
    public function read(int $accountId, #[CurrentUser] User $user): JsonResponse
    {
        $this->requireOwnAccount($accountId, $user);
        $completed = $this->connection->fetchOne(
            'SELECT completed_level FROM rest_block_blast_campaign WHERE user_id = ?',
            [$accountId],
        );

        return $this->progressResponse((int) $completed);
    }

    #[Route('', name: 'spa_api_rest_block_blast_campaign_save', methods: ['PUT'])]
    public function save(int $accountId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->requireOwnAccount($accountId, $user);
        $completed = $request->toArray()['completed'] ?? null;
        if (!is_int($completed) || $completed < 1 || $completed > self::LAST_LEVEL) {
            return $this->json(['error' => 'invalid_campaign_level'], Response::HTTP_BAD_REQUEST);
        }

        $saved = $this->connection->fetchOne(
            'INSERT INTO rest_block_blast_campaign (user_id, completed_level) VALUES (?, ?)
             ON CONFLICT (user_id) DO UPDATE SET completed_level =
                 GREATEST(rest_block_blast_campaign.completed_level, EXCLUDED.completed_level)
             RETURNING completed_level',
            [$accountId, $completed],
        );

        return $this->progressResponse((int) $saved);
    }

    private function requireOwnAccount(int $accountId, User $user): void
    {
        if ($user->getId() !== $accountId) {
            throw $this->createAccessDeniedException();
        }
    }

    private function progressResponse(int $completed): JsonResponse
    {
        return $this->json(['completed' => $completed], headers: ['Cache-Control' => 'private, no-store']);
    }
}
