<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Rest;

use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/rest/narde')]
final class RestNardeController extends AbstractController
{
    private const RATING = 1200;
    private const WAITING = "status = 'waiting' AND updated_at > CURRENT_TIMESTAMP -
        CASE WHEN mode = 'queue' THEN INTERVAL '90 seconds' ELSE INTERVAL '30 minutes' END";

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'spa_api_rest_narde_state', methods: ['GET'])]
    public function state(#[CurrentUser] User $user): JsonResponse
    {
        $db = $this->em->getConnection();
        $id = $user->getId();
        $match = $this->findCurrent($db, $id);
        if ($match === false) {
            $match = $db->fetchAssociative(
                "SELECT * FROM rest_narde_match WHERE (host_id = ? OR guest_id = ?) AND status = 'finished'
                ORDER BY updated_at DESC, code DESC LIMIT 1",
                [$id, $id],
            );
        } elseif ($match['mode'] === 'queue' && $match['status'] === 'waiting') {
            $db->executeStatement(
                "UPDATE rest_narde_match SET updated_at = CURRENT_TIMESTAMP WHERE code = ? AND status = 'waiting'
                AND updated_at BETWEEN CURRENT_TIMESTAMP - INTERVAL '90 seconds' AND CURRENT_TIMESTAMP - INTERVAL '15 seconds'",
                [$match['code']],
            );
        }

        return $this->reply($match, $user);
    }

    #[Route('', name: 'spa_api_rest_narde_action', methods: ['POST'])]
    public function action(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $request->toArray();
        $action = $data['action'] ?? '';
        $code = $data['code'] ?? '';
        if (!is_string($code) || ($code !== '' && !preg_match('/^[0-9A-F]{8}$/D', $code))) {
            return $this->json(['error' => 'invalid_code'], 400);
        }

        $id = $user->getId();
        try {
            $match = $this->em->getConnection()->transactional(
                fn (Connection $db) => match ($action) {
                    'queue', 'private', 'bot', 'join' => $this->enter($db, $id, $action, $code),
                    'cancel', 'roll', 'move', 'resign', 'timeout' => $this->play($db, $id, $action, $code, $data),
                    default => throw new ConflictHttpException('invalid_action'),
                },
            );
        } catch (HttpExceptionInterface $error) {
            return $this->json(['error' => $error->getMessage()], $error->getStatusCode());
        }

        return $this->reply($match, $user);
    }

    private function findCurrent(Connection $db, int $id): array|false
    {
        return $db->fetchAssociative(
            "SELECT * FROM rest_narde_match WHERE (host_id = ? OR guest_id = ?)
            AND (status = 'active' OR (" . self::WAITING . "))
            ORDER BY updated_at DESC, code DESC LIMIT 1",
            [$id, $id],
        );
    }

    private function enter(Connection $db, int $id, string $action, string $code): array
    {
        // Only lobby admission is serialized. Turns lock their own match row.
        $db->executeQuery("SELECT pg_advisory_xact_lock(hashtext('pulse_rest_narde_lobby'))");
        $current = $this->findCurrent($db, $id);
        if ($current !== false) {
            if ($action === 'join' && $current['code'] !== $code) {
                throw new ConflictHttpException('active_match_exists');
            }

            return $current;
        }

        $opponent = false;
        if ($action === 'join') {
            $opponent = $db->fetchAssociative(
                "SELECT * FROM rest_narde_match WHERE code = ? AND mode = 'private' AND " . self::WAITING . ' FOR UPDATE',
                [$code],
            );
            if ($opponent === false) {
                throw new NotFoundHttpException('room_unavailable');
            }
        } elseif ($action === 'queue') {
            $opponent = $db->fetchAssociative(
                "SELECT * FROM rest_narde_match WHERE mode = 'queue' AND " . self::WAITING . '
                ORDER BY updated_at, code LIMIT 1 FOR UPDATE',
            );
        }
        if ($opponent !== false) {
            $state = LongNarde::start();

            return $db->fetchAssociative(
                "UPDATE rest_narde_match SET guest_id = ?, status = 'active', state = ?, version = version + 1,
                updated_at = CURRENT_TIMESTAMP WHERE code = ? RETURNING *",
                [$id, json_encode($state, JSON_THROW_ON_ERROR), $opponent['code']],
            );
        }

        $db->executeStatement("DELETE FROM rest_narde_match WHERE status = 'waiting'
            AND updated_at <= CURRENT_TIMESTAMP - INTERVAL '30 minutes'");
        $state = LongNarde::start();
        if ($action === 'bot') {
            $state = LongNarde::computerTurn($state);
        }
        do {
            $match = $db->fetchAssociative(
                'INSERT INTO rest_narde_match (code, host_id, mode, status, state, version, updated_at)
                VALUES (?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP) ON CONFLICT (code) DO NOTHING RETURNING *',
                [strtoupper(bin2hex(random_bytes(4))), $id, $action, $action === 'bot' ? 'active' : 'waiting',
                    json_encode($state, JSON_THROW_ON_ERROR)],
            );
        } while ($match === false);

        return $match;
    }

    private function play(Connection $db, int $id, string $action, string $code, array $data): array|false
    {
        $match = $db->fetchAssociative(
            'SELECT * FROM rest_narde_match WHERE code = ? AND (host_id = ? OR guest_id = ?) FOR UPDATE',
            [$code, $id, $id],
        );
        if ($match === false) {
            throw new NotFoundHttpException('room_unavailable');
        }
        if ($match['status'] === 'finished') {
            return $match;
        }
        if (($data['version'] ?? null) !== (int) $match['version']) {
            throw new ConflictHttpException('match_changed');
        }
        if ($action === 'cancel' && $match['status'] === 'waiting') {
            $db->delete('rest_narde_match', ['code' => $code]);

            return false;
        }
        if ($match['status'] !== 'active') {
            throw new ConflictHttpException('match_changed');
        }

        $state = json_decode($match['state'], true, 512, JSON_THROW_ON_ERROR);
        $side = (int) $match['host_id'] === $id ? 0 : 1;
        if ($action === 'resign' || $action === 'timeout') {
            if ($action === 'timeout' && ($match['mode'] === 'bot' || $state['turn'] === $side
                || !$this->timeoutElapsed($db, $code))) {
                throw new ConflictHttpException('timeout_unavailable');
            }
            $state['winner'] = $action === 'resign' ? 1 - $side : $side;
            $state['reason'] = $action === 'resign' ? 'resigned' : 'timeout';
            $state['pointsWon'] = 1;
            $state['dice'] = [];
        } elseif ($state['turn'] !== $side) {
            throw new ConflictHttpException('not_your_turn');
        } elseif ($action === 'roll' && $state['dice'] === []) {
            $state = LongNarde::roll($state);
        } elseif ($action === 'move') {
            $move = ['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null, 'die' => $data['die'] ?? null];
            if (!in_array($move, LongNarde::legalMoves($state), true)) {
                throw new ConflictHttpException('illegal_move');
            }
            $state = LongNarde::move($state, $move);
        } else {
            throw new ConflictHttpException('match_changed');
        }

        if ($match['mode'] === 'bot') {
            $state = LongNarde::computerTurn($state);
        }
        if ($state['winner'] !== null && $match['mode'] === 'queue') {
            $state['ratingDelta'] = $this->rate($db, [(int) $match['host_id'], (int) $match['guest_id']], $state['winner']);
        }

        return $db->fetchAssociative(
            'UPDATE rest_narde_match SET status = ?, state = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP
            WHERE code = ? RETURNING *',
            [$state['winner'] === null ? 'active' : 'finished', json_encode($state, JSON_THROW_ON_ERROR), $code],
        );
    }

    private function timeoutElapsed(Connection $db, string $code): bool
    {
        return (int) $db->fetchOne(
            "SELECT COUNT(*) FROM rest_narde_match WHERE code = ? AND updated_at <= CURRENT_TIMESTAMP - INTERVAL '5 minutes'",
            [$code],
        ) > 0;
    }

    private function rate(Connection $db, array $users, int $winner): array
    {
        $order = $users;
        sort($order, SORT_NUMERIC);
        $ratings = [];
        foreach ($order as $id) {
            $db->executeStatement(
                "INSERT INTO rest_game_score (user_id, game, score, updated_at) VALUES (?, 'backgammon', ?, CURRENT_TIMESTAMP)
                ON CONFLICT (user_id, game) DO NOTHING",
                [$id, self::RATING],
            );
            $ratings[$id] = (int) $db->fetchOne(
                "SELECT score FROM rest_game_score WHERE user_id = ? AND game = 'backgammon' FOR UPDATE",
                [$id],
            );
        }
        $expected = 1 / (1 + 10 ** (($ratings[$users[1]] - $ratings[$users[0]]) / 400));
        $delta = (int) round(32 * (($winner === 0 ? 1 : 0) - $expected));
        foreach ($users as $side => $id) {
            $db->executeStatement(
                "UPDATE rest_game_score SET score = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND game = 'backgammon'",
                [$ratings[$id] + ($side === 0 ? $delta : -$delta), $id],
            );
        }

        return [$delta, -$delta];
    }

    private function reply(array|false $match, User $user): JsonResponse
    {
        if ($match === false) {
            return $this->json(['match' => null], headers: ['Cache-Control' => 'no-store']);
        }

        $state = json_decode($match['state'], true, 512, JSON_THROW_ON_ERROR);
        $side = (int) $match['host_id'] === $user->getId() ? 0 : 1;
        $opponentId = $match[$side === 0 ? 'guest_id' : 'host_id'];
        $opponent = $opponentId === null ? null : $this->em->find(User::class, (int) $opponentId);
        $opponentName = $opponent === null ? null
            : (trim($opponent->getLastname() . ' ' . $opponent->getFirstname()) ?: $opponent->getLogin());
        $legal = $match['status'] === 'active' && $side === $state['turn'] ? LongNarde::legalMoves($state) : [];

        return $this->json(['match' => [
            'code' => $match['code'], 'mode' => $match['mode'], 'status' => $match['status'],
            'version' => (int) $match['version'], 'side' => $side,
            'opponent' => $match['mode'] === 'bot' ? 'Компьютер' : $opponentName,
            'turn' => $state['turn'], 'points' => $state['points'], 'off' => $state['off'],
            'dice' => $state['dice'], 'rolled' => $state['rolled'], 'legal' => $legal, 'lastMoves' => $state['lastMoves'],
            'winner' => $state['winner'], 'reason' => $state['reason'], 'pointsWon' => $state['pointsWon'],
            'ratingDelta' => $state['ratingDelta'][$side] ?? null,
            'canClaim' => $match['status'] === 'active' && $match['mode'] !== 'bot' && $side !== $state['turn']
                && $this->timeoutElapsed($this->em->getConnection(), $match['code']),
        ]], headers: ['Cache-Control' => 'no-store']);
    }
}
