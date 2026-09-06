<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Rest;

use App\Entity\Rest\RestGameScore;
use App\Entity\User\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
    /**
     * Белый список игр (BE-26): произвольное имя давало 500 на varchar(50) и
     * неограниченный рост таблицы; теперь строк не больше users × GAMES.
     * Единственный клиент — analytics_platform/public/rest/index.js — шлёт 'zuma'.
     */
    private const GAMES = ['zuma'];
    /** Реальный потолок очков игры; заодно отсекает «integer out of range» на INT-колонке. */
    private const MAX_SCORE = 1_000_000;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'spa_api_rest_leaderboard_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $game = $this->resolveGame($request->query->getString('game'));
        if ($game === null) {
            return $this->json(['error' => 'invalid_game'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['items' => $this->topItems($game)]);
    }

    #[Route('', name: 'spa_api_rest_leaderboard_submit', methods: ['POST'])]
    public function submit(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $request->toArray();
        if (!array_key_exists('score', $data) || !is_numeric($data['score'])) {
            return $this->json(['error' => 'invalid_score'], Response::HTTP_BAD_REQUEST);
        }
        $score = (int) $data['score'];
        if ($score < 0 || $score > self::MAX_SCORE) {
            return $this->json(['error' => 'invalid_score'], Response::HTTP_BAD_REQUEST);
        }

        $game = $this->resolveGame(isset($data['game']) ? (string) $data['game'] : '');
        if ($game === null) {
            return $this->json(['error' => 'invalid_game'], Response::HTTP_BAD_REQUEST);
        }

        $repo = $this->em->getRepository(RestGameScore::class);
        $row = $repo->findOneBy(['user' => $user, 'game' => $game]);
        $newRecord = false;
        if ($row === null) {
            $this->em->persist(new RestGameScore($user, $game, $score));
        } elseif ($score > $row->getScore()) {
            $row->setScore($score);
            $newRecord = true;
        }
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Два параллельных POST одного пользователя с новой игрой: второй
            // упёрся в uniq_rest_game_score_user_game — это конфликт, а не 500.
            return $this->json(['error' => 'conflict'], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'items' => $this->topItems($game),
            'newRecord' => $newRecord,
        ]);
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

    /** null — игра не из белого списка. */
    private function resolveGame(string $game): ?string
    {
        $game = trim($game);
        if ($game === '') {
            return self::DEFAULT_GAME;
        }

        return in_array($game, self::GAMES, true) ? $game : null;
    }
}
