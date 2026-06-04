<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Kanban;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\User\User;
use App\Enum\Kanban\KanbanBoardMemberRole;
use App\Enum\Kanban\KanbanColumnColor;
use App\Repository\Kanban\KanbanBoardRepository;
use App\Repository\Kanban\KanbanColumnRepository;
use App\Repository\Kanban\Project\KanbanProjectRepository;
use App\Service\Kanban\KanbanService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/projects/{projectId}/boards/{boardId}/columns')]
final class ColumnController extends AbstractController
{
    public function __construct(
        private readonly KanbanProjectRepository $projectRepository,
        private readonly KanbanBoardRepository $boardRepository,
        private readonly KanbanColumnRepository $columnRepository,
        private readonly KanbanService $kanbanService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'spa_api_project_board_column_create', requirements: [
        'projectId' => '\d+',
        'boardId' => '\d+',
    ], methods: ['POST'])]
    public function create(
        int $projectId,
        int $boardId,
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $project = $this->projectRepository->find($projectId);
        if ($project === null) {
            return $this->json(['error' => SpaApiError::PROJECT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $board = $this->boardRepository->find($boardId);
        if ($board === null || $board->getProject()?->getId() !== $project->getId()) {
            return $this->json(['error' => SpaApiError::BOARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::KANBAN_ADMIN);

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            return $this->json(['error' => SpaApiError::COLUMN_TITLE_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $color = KanbanColumnColor::tryFrom((string) ($payload['headerColor'] ?? '')) ?? KanbanColumnColor::BG_PRIMARY;
        $column = $this->kanbanService->createColumn($board, $title, $color);

        return $this->json([
            'id' => $column->getId(),
            'title' => $column->getTitle(),
            'headerColor' => $column->getHeaderColor()->value,
            'position' => $column->getPosition(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{columnId}', name: 'spa_api_project_board_column_patch', requirements: [
        'projectId' => '\d+',
        'boardId' => '\d+',
        'columnId' => '\d+',
    ], methods: ['PATCH'])]
    public function patch(
        int $projectId,
        int $boardId,
        int $columnId,
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $project = $this->projectRepository->find($projectId);
        if ($project === null) {
            return $this->json(['error' => SpaApiError::PROJECT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $board = $this->boardRepository->find($boardId);
        if ($board === null || $board->getProject()?->getId() !== $project->getId()) {
            return $this->json(['error' => SpaApiError::BOARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::KANBAN_EDITOR);

        $column = $this->columnRepository->find($columnId);
        if ($column === null || $column->getBoard()->getId() !== $boardId) {
            return $this->json(['error' => SpaApiError::COLUMN_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $hasTitle = isset($payload['title']) && trim((string) $payload['title']) !== '';
        $hasHeaderColor = isset($payload['headerColor']);
        $hasPosition = isset($payload['position']);

        if (!$hasTitle && !$hasHeaderColor && !$hasPosition) {
            return $this->json(['error' => SpaApiError::UPDATE_FIELDS_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        if ($hasTitle) {
            $column->setTitle(trim((string) $payload['title']));
        }
        if ($hasHeaderColor) {
            $color = KanbanColumnColor::tryFrom((string) $payload['headerColor']);
            if ($color !== null) {
                $column->setHeaderColor($color);
            }
        }
        if ($hasPosition) {
            $column->setPosition((float) $payload['position']);
        }

        $this->entityManager->flush();

        if ($hasPosition) {
            $columns = $this->columnRepository->createQueryBuilder('c')
                ->where('c.board = :board')
                ->setParameter('board', $board)
                ->orderBy('c.position', 'ASC')
                ->getQuery()
                ->getResult();

            $needsRebalance = false;
            for ($i = 1, $count = count($columns); $i < $count; $i++) {
                if (abs($columns[$i]->getPosition() - $columns[$i - 1]->getPosition()) < 1e-5) {
                    $needsRebalance = true;
                    break;
                }
            }

            if ($needsRebalance) {
                $this->columnRepository->rebalancePositions($board);
                $this->entityManager->flush();
            }
        }

        return $this->json([
            'id' => $column->getId(),
            'title' => $column->getTitle(),
            'headerColor' => $column->getHeaderColor()->value,
            'position' => $column->getPosition(),
        ]);
    }

    #[Route('/{columnId}', name: 'spa_api_project_board_column_delete', requirements: [
        'projectId' => '\d+',
        'boardId' => '\d+',
        'columnId' => '\d+',
    ], methods: ['DELETE'])]
    public function delete(
        int $projectId,
        int $boardId,
        int $columnId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $project = $this->projectRepository->find($projectId);
        if ($project === null) {
            return $this->json(['error' => SpaApiError::PROJECT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $board = $this->boardRepository->find($boardId);
        if ($board === null || $board->getProject()?->getId() !== $project->getId()) {
            return $this->json(['error' => SpaApiError::BOARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::KANBAN_ADMIN);

        $column = $this->columnRepository->find($columnId);
        if ($column === null || $column->getBoard()->getId() !== $boardId) {
            return $this->json(['error' => SpaApiError::COLUMN_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->deleteColumn($column);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
