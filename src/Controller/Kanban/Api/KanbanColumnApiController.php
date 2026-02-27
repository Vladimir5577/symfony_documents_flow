<?php

namespace App\Controller\Kanban\Api;

use App\Entity\User\User;
use App\Enum\KanbanBoardMemberRole;
use App\Enum\KanbanColumnColor;
use App\Repository\Kanban\KanbanBoardRepository;
use App\Repository\Kanban\KanbanColumnRepository;
use App\Service\Kanban\KanbanService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/kanban/boards/{boardId}/columns')]
final class KanbanColumnApiController extends AbstractController
{
    public function __construct(
        private readonly KanbanBoardRepository $boardRepo,
        private readonly KanbanColumnRepository $columnRepo,
        private readonly KanbanService $kanbanService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_kanban_columns_create', methods: ['POST'])]
    public function create(int $boardId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $board = $this->boardRepo->find($boardId);
        if (!$board) {
            return $this->json(['error' => 'Доска не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::EDITOR);

        $payload = json_decode($request->getContent(), true) ?? [];
        $title = trim($payload['title'] ?? '');
        if ($title === '') {
            return $this->json(['error' => 'Название обязательно.'], Response::HTTP_BAD_REQUEST);
        }

        $color = KanbanColumnColor::tryFrom($payload['headerColor'] ?? '') ?? KanbanColumnColor::BG_PRIMARY;
        $column = $this->kanbanService->createColumn($board, $title, $color);

        return $this->json([
            'id' => $column->getId(),
            'title' => $column->getTitle(),
            'headerColor' => $column->getHeaderColor()->value,
            'position' => $column->getPosition(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_kanban_columns_update', methods: ['PATCH'])]
    public function update(int $boardId, int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $board = $this->boardRepo->find($boardId);
        if (!$board) {
            return $this->json(['error' => 'Доска не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::EDITOR);

        $column = $this->columnRepo->find($id);
        if (!$column || $column->getBoard()->getId() !== $boardId) {
            return $this->json(['error' => 'Колонка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true) ?? [];

        if (isset($payload['title']) && trim($payload['title']) !== '') {
            $column->setTitle(trim($payload['title']));
        }
        if (isset($payload['headerColor'])) {
            $color = KanbanColumnColor::tryFrom($payload['headerColor']);
            if ($color) {
                $column->setHeaderColor($color);
            }
        }
        if (isset($payload['position'])) {
            $column->setPosition((float) $payload['position']);
        }

        $this->em->flush();

        return $this->json([
            'id' => $column->getId(),
            'title' => $column->getTitle(),
            'headerColor' => $column->getHeaderColor()->value,
            'position' => $column->getPosition(),
        ]);
    }

    #[Route('/{id}', name: 'api_kanban_columns_delete', methods: ['DELETE'])]
    public function delete(int $boardId, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $board = $this->boardRepo->find($boardId);
        if (!$board) {
            return $this->json(['error' => 'Доска не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::ADMIN);

        $column = $this->columnRepo->find($id);
        if (!$column || $column->getBoard()->getId() !== $boardId) {
            return $this->json(['error' => 'Колонка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->deleteColumn($column);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
