<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Rest;

use App\Entity\Rest\RestGameScore;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/rest/leaderboard')]
final class RestLeaderboardController extends AbstractController
{
    private const TOP = 10;
    private const DEFAULT_GAME = 'zuma';
    private const BLOCK_BLAST = 'block-blast';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'spa_api_rest_leaderboard_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $game = $this->resolveGame($request->query->getString('game'));

        $result = ['items' => $this->topItems($game)];
        if ($game === 'backgammon' || $game === self::BLOCK_BLAST) {
            $result['personal'] = $this->personalScore($user, $game);
        }

        return $this->json($result);
    }

    #[Route('', name: 'spa_api_rest_leaderboard_submit', methods: ['POST'])]
    public function submit(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $request->toArray();
        $game = $this->resolveGame(isset($data['game']) && is_string($data['game']) ? $data['game'] : '');
        if ($game === 'backgammon') {
            return $this->json(['error' => 'rating_calculated_by_server'], Response::HTTP_BAD_REQUEST);
        }
        if ($game === 'tetris' || $game === self::BLOCK_BLAST) {
            $score = $data['score'] ?? null;
            if (!is_int($score) || $score < 0 || $score > 2147483647) {
                return $this->json(['error' => 'invalid_score'], Response::HTTP_BAD_REQUEST);
            }
            $newRecord = $this->em->getConnection()->executeStatement(
                'INSERT INTO rest_game_score (user_id, game, score, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT (user_id, game) DO UPDATE SET score = EXCLUDED.score, updated_at = EXCLUDED.updated_at
                WHERE rest_game_score.score < EXCLUDED.score',
                [$user->getId(), $game, $score],
            ) > 0;

            $result = ['items' => $this->topItems($game), 'newRecord' => $newRecord];
            if ($game === self::BLOCK_BLAST) {
                $result['personal'] = $this->personalScore($user, $game);
            }

            return $this->json($result);
        }
        if (!array_key_exists('score', $data) || !is_numeric($data['score'])) {
            return $this->json(['error' => 'invalid_score'], Response::HTTP_BAD_REQUEST);
        }
        $score = (int) $data['score'];


        $repo = $this->em->getRepository(RestGameScore::class);
        $row = $repo->findOneBy(['user' => $user, 'game' => $game]);
        $newRecord = false;
        if ($row === null) {
            $this->em->persist(new RestGameScore($user, $game, $score));
        } elseif ($score > $row->getScore()) {
            $row->setScore($score);
            $newRecord = true;
        }
        $this->em->flush();

        return $this->json([
            'items' => $this->topItems($game),
            'newRecord' => $newRecord,
        ]);
    }

    private function personalScore(User $user, string $game): ?int
    {
        return $this->em->getRepository(RestGameScore::class)
            ->findOneBy(['user' => $user, 'game' => $game])?->getScore();
    }

    /** @return list<array{name: string, score: int}> */
    private function topItems(string $game): array
    {
        /** @var RestGameScore[] $rows */
        $rows = $this->em->getRepository(RestGameScore::class)->findBy(
            ['game' => $game],
            ['score' => 'DESC'],
            self::TOP,
        );

        return array_map(static function (RestGameScore $row): array {
            $u = $row->getUser();
            $name = trim($u->getLastname() . ' ' . $u->getFirstname()) ?: ($u->getLogin() ?? (string) $u->getId());

            return ['name' => $name, 'score' => $row->getScore()];
        }, $rows);
    }

    private function resolveGame(string $game): string
    {
        $game = trim($game);

        return $game !== '' ? $game : self::DEFAULT_GAME;
    }
}
