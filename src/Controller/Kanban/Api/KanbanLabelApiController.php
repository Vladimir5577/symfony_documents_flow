<?php

namespace App\Controller\Kanban\Api;

use App\Entity\Kanban\KanbanLabel;
use App\Entity\User\User;
use App\Enum\KanbanBoardMemberRole;
use App\Enum\KanbanColumnColor;
use App\Repository\Kanban\KanbanBoardRepository;
use App\Repository\Kanban\KanbanCardRepository;
use App\Repository\Kanban\KanbanLabelRepository;
use App\Service\Kanban\KanbanService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/kanban/boards/{boardId}/labels')]
final class KanbanLabelApiController extends AbstractController
{
    public function __construct(
        private readonly KanbanBoardRepository $boardRepo,
        private readonly KanbanLabelRepository $labelRepo,
        private readonly KanbanCardRepository $cardRepo,
        private readonly KanbanService $kanbanService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_kanban_labels_list', methods: ['GET'])]
    public function list(string $boardId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $board = $this->boardRepo->find($boardId);
        if (!$board) {
            return $this->json(['error' => 'Доска не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::VIEWER);

        $labels = $this->labelRepo->findBy(['board' => $board]);

        $data = array_map(fn(KanbanLabel $l) => [
            'id' => (string) $l->getId(),
            'name' => $l->getName(),
            'color' => $l->getColor()->value,
        ], $labels);

        return $this->json($data);
    }

    #[Route('', name: 'api_kanban_labels_create', methods: ['POST'])]
    public function create(string $boardId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $board = $this->boardRepo->find($boardId);
        if (!$board) {
            return $this->json(['error' => 'Доска не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::EDITOR);

        $payload = json_decode($request->getContent(), true) ?? [];
        $name = trim($payload['name'] ?? '');
        if ($name === '') {
            return $this->json(['error' => 'name обязателен.'], Response::HTTP_BAD_REQUEST);
        }

        $color = KanbanColumnColor::tryFrom($payload['color'] ?? '') ?? KanbanColumnColor::BG_PRIMARY;

        $label = new KanbanLabel();
        $label->setName($name);
        $label->setColor($color);
        $label->setBoard($board);

        $this->em->persist($label);
        $this->em->flush();

        return $this->json([
            'id' => (string) $label->getId(),
            'name' => $label->getName(),
            'color' => $label->getColor()->value,
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_kanban_labels_delete', methods: ['DELETE'])]
    public function delete(string $boardId, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $board = $this->boardRepo->find($boardId);
        if (!$board) {
            return $this->json(['error' => 'Доска не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::ADMIN);

        $label = $this->labelRepo->find($id);
        if (!$label || (string) $label->getBoard()->getId() !== $boardId) {
            return $this->json(['error' => 'Метка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($label);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/cards/{cardId}/{labelId}', name: 'api_kanban_labels_toggle', methods: ['POST'])]
    public function toggleLabel(string $boardId, string $cardId, string $labelId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $board = $this->boardRepo->find($boardId);
        if (!$board) {
            return $this->json(['error' => 'Доска не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::EDITOR);

        $card = $this->cardRepo->find($cardId);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $label = $this->labelRepo->find($labelId);
        if (!$label || (string) $label->getBoard()->getId() !== $boardId) {
            return $this->json(['error' => 'Метка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        if ($card->getLabels()->contains($label)) {
            $card->removeLabel($label);
            $action = 'detached';
        } else {
            $card->addLabel($label);
            $action = 'attached';
        }

        $this->em->flush();

        return $this->json(['action' => $action, 'labelId' => (string) $label->getId()]);
    }
}
