<?php

namespace App\Controller\Kanban\Api;

use App\Entity\Kanban\KanbanCardActivity;
use App\Entity\User\User;
use App\Enum\Kanban\KanbanBoardMemberRole;
use App\Repository\Kanban\KanbanCardActivityRepository;
use App\Repository\Kanban\KanbanCardRepository;
use App\Service\Kanban\KanbanService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/kanban/cards/{cardId}/activities')]
final class KanbanCardActivityApiController extends AbstractController
{
    private const PAGE_SIZE = 30;

    public function __construct(
        private readonly KanbanCardRepository $cardRepo,
        private readonly KanbanCardActivityRepository $activityRepo,
        private readonly KanbanService $kanbanService,
    ) {
    }

    #[Route('', name: 'api_kanban_activities_list', methods: ['GET'])]
    public function list(int $cardId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($cardId);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        // Историю видят все, кто имеет доступ к доске (как и саму карточку).
        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_VIEWER);

        $offset = max(0, $request->query->getInt('offset', 0));

        // Берём на одну запись больше, чтобы узнать, есть ли продолжение.
        $rows = $this->activityRepo->findByCard($card, $offset, self::PAGE_SIZE + 1);
        $hasMore = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);

        $items = array_map(function (KanbanCardActivity $a) {
            $author = $a->getUser();
            return [
                'type' => $a->getType()->value,
                'label' => $a->getType()->getLabel(),
                'icon' => $a->getType()->getIcon(),
                'oldValue' => $a->getOldValue(),
                'newValue' => $a->getNewValue(),
                'user' => $author ? [
                    'id' => $author->getId(),
                    'name' => trim($author->getLastname() . ' ' . $author->getFirstname())
                        ?: ($author->getLogin() ?? (string) $author->getId()),
                ] : null,
                'createdAt' => $a->getCreatedAt()?->format('c'),
            ];
        }, $rows);

        return $this->json([
            'items' => $items,
            'hasMore' => $hasMore,
            'nextOffset' => $offset + count($items),
        ]);
    }
}
