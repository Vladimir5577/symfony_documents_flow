<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Kanban;

use App\Controller\SpaApi\SpaApiError;
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
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/cards/{cardId}/activities')]
final class ActivityController extends AbstractController
{
    private const PAGE_SIZE = 30;

    public function __construct(
        private readonly KanbanCardRepository $cardRepo,
        private readonly KanbanCardActivityRepository $activityRepo,
        private readonly KanbanService $kanbanService,
    ) {
    }

    #[Route('', name: 'spa_api_cards_activities_list', requirements: ['cardId' => '\d+'], methods: ['GET'])]
    public function list(int $cardId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $card = $this->cardRepo->find($cardId);
        if ($card === null) {
            return $this->json(['error' => SpaApiError::CARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        // Историю видят все, кто имеет доступ к доске (как и саму карточку).
        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_VIEWER);

        $offset = max(0, $request->query->getInt('offset', 0));

        // Берём на одну запись больше, чтобы узнать, есть ли продолжение.
        $rows = $this->activityRepo->findByCard($card, $offset, self::PAGE_SIZE + 1);
        $hasMore = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);

        $items = array_map(function (KanbanCardActivity $activity): array {
            $author = $activity->getUser();

            return [
                'type' => $activity->getType()->value,
                'label' => $activity->getType()->getLabel(),
                'icon' => $activity->getType()->getIcon(),
                'oldValue' => $activity->getOldValue(),
                'newValue' => $activity->getNewValue(),
                'user' => $author !== null ? [
                    'id' => $author->getId(),
                    'name' => trim($author->getLastname() . ' ' . $author->getFirstname())
                        ?: ($author->getLogin() ?? (string) $author->getId()),
                ] : null,
                'createdAt' => $activity->getCreatedAt()?->format('c'),
            ];
        }, $rows);

        return $this->json([
            'items' => $items,
            'hasMore' => $hasMore,
            'nextOffset' => $offset + count($items),
        ]);
    }
}
