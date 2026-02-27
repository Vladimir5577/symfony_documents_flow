<?php

namespace App\Controller\Kanban\Api;

use App\Entity\Kanban\KanbanChecklistItem;
use App\Entity\User\User;
use App\Enum\KanbanBoardMemberRole;
use App\Repository\Kanban\KanbanCardRepository;
use App\Repository\Kanban\KanbanChecklistItemRepository;
use App\Service\Kanban\KanbanService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/kanban/cards/{cardId}/checklist')]
final class KanbanChecklistApiController extends AbstractController
{
    public function __construct(
        private readonly KanbanCardRepository $cardRepo,
        private readonly KanbanChecklistItemRepository $checklistRepo,
        private readonly KanbanService $kanbanService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_kanban_checklist_create', methods: ['POST'])]
    public function create(int $cardId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($cardId);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::EDITOR);

        $payload = json_decode($request->getContent(), true) ?? [];
        $title = trim($payload['title'] ?? '');
        if ($title === '') {
            return $this->json(['error' => 'title обязателен.'], Response::HTTP_BAD_REQUEST);
        }

        $maxPos = $this->checklistRepo->getMaxPosition($card);

        $item = new KanbanChecklistItem();
        $item->setTitle($title);
        $item->setPosition($maxPos + 1.0);
        $item->setCard($card);

        $this->em->persist($item);
        $this->em->flush();

        return $this->json([
            'id' => $item->getId(),
            'title' => $item->getTitle(),
            'isCompleted' => $item->isCompleted(),
            'position' => $item->getPosition(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_kanban_checklist_update', methods: ['PATCH'])]
    public function update(int $cardId, int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($cardId);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::EDITOR);

        $item = $this->checklistRepo->find($id);
        if (!$item || $item->getCard()->getId() !== $cardId) {
            return $this->json(['error' => 'Элемент не найден.'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true) ?? [];

        if (isset($payload['title']) && trim($payload['title']) !== '') {
            $item->setTitle(trim($payload['title']));
        }
        if (array_key_exists('isCompleted', $payload)) {
            $item->setIsCompleted((bool) $payload['isCompleted']);
        }

        $this->em->flush();

        return $this->json([
            'id' => $item->getId(),
            'title' => $item->getTitle(),
            'isCompleted' => $item->isCompleted(),
            'position' => $item->getPosition(),
        ]);
    }

    #[Route('/{id}', name: 'api_kanban_checklist_delete', methods: ['DELETE'])]
    public function delete(int $cardId, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($cardId);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::EDITOR);

        $item = $this->checklistRepo->find($id);
        if (!$item || $item->getCard()->getId() !== $cardId) {
            return $this->json(['error' => 'Элемент не найден.'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($item);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
