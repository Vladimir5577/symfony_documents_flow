<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Kanban;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Kanban\KanbanBoard;
use App\Entity\Kanban\KanbanColumn;
use App\Entity\User\User;
use App\Repository\Kanban\KanbanBoardRepository;
use App\Repository\Kanban\Project\KanbanProjectRepository;
use App\Service\Kanban\KanbanService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/projects')]
final class BoardController extends AbstractController
{
    public function __construct(
        private readonly KanbanProjectRepository $projectRepository,
        private readonly KanbanBoardRepository $boardRepository,
        private readonly KanbanService $kanbanService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/{id}/boards/{boardId}', name: 'spa_api_project_board_show', requirements: ['id' => '\d+', 'boardId' => '\d+'], methods: ['GET'])]
    public function showBoard(int $id, int $boardId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $project = $this->projectRepository->find($id);
        if ($project === null) {
            return $this->json(['error' => SpaApiError::PROJECT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $board = $this->boardRepository->findOneWithRelations($boardId);
        if ($board === null || $board->getProject()?->getId() !== $project->getId()) {
            return $this->json(['error' => SpaApiError::BOARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $columns = [];
        foreach ($board->getColumns() as $col) {
            $columns[] = $this->formatColumn($col);
        }

        return $this->json([
            'id' => $board->getId(),
            'title' => $board->getTitle(),
            'updatedAt' => $board->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'columns' => $columns,
        ]);
    }

    #[Route('/{id}/boards', name: 'spa_api_project_board_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function createBoard(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $project = $this->projectRepository->find($id);
        if ($project === null) {
            return $this->json(['error' => SpaApiError::PROJECT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $projectId = $project->getId();
        if ($projectId === null) {
            return $this->json(['error' => SpaApiError::PROJECT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            return $this->json(['error' => SpaApiError::BOARD_TITLE_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $columnsRaw = $payload['columns'] ?? null;
        $columns = is_array($columnsRaw)
            ? array_values(array_filter(array_map(static fn ($column) => trim((string) $column), $columnsRaw)))
            : [];
        if ($columns === []) {
            $columns = ['Backlog', 'To Do', 'In Progress', 'Done'];
        }

        $board = $this->kanbanService->createBoard($project, $title, $user);
        foreach ($columns as $columnTitle) {
            if ($columnTitle !== '') {
                $this->kanbanService->createColumn($board, $columnTitle);
            }
        }

        return $this->json($this->formatBoard($board, $projectId), Response::HTTP_CREATED);
    }

    #[Route('/{id}/boards/{boardId}', name: 'spa_api_project_board_update', requirements: ['id' => '\d+', 'boardId' => '\d+'], methods: ['PATCH'])]
    public function updateBoard(int $id, int $boardId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $project = $this->projectRepository->find($id);
        if ($project === null) {
            return $this->json(['error' => SpaApiError::PROJECT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $projectId = $project->getId();
        if ($projectId === null) {
            return $this->json(['error' => SpaApiError::PROJECT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $board = $this->boardRepository->find($boardId);
        if ($board === null || $board->getProject()?->getId() !== $projectId) {
            return $this->json(['error' => SpaApiError::BOARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        if (!array_key_exists('title', $payload)) {
            return $this->json(['error' => SpaApiError::BOARD_TITLE_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $title = trim((string) $payload['title']);
        if ($title === '') {
            return $this->json(['error' => SpaApiError::BOARD_TITLE_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        if (mb_strlen($title) > 200) {
            return $this->json(
                ['error' => SpaApiError::BOARD_TITLE_TOO_LONG],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $board->setTitle($title);
        $this->entityManager->flush();

        return $this->json($this->formatBoard($board, $projectId));
    }

    #[Route('/{id}/boards/{boardId}', name: 'spa_api_project_board_delete', requirements: ['id' => '\d+', 'boardId' => '\d+'], methods: ['DELETE'])]
    public function deleteBoard(int $id, int $boardId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $project = $this->projectRepository->find($id);
        if ($project === null) {
            return $this->json(['error' => SpaApiError::PROJECT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $board = $this->boardRepository->find($boardId);
        if ($board === null || $board->getProject()?->getId() !== $project->getId()) {
            return $this->json(['error' => SpaApiError::BOARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $nextBoardId = null;
        foreach ($this->boardRepository->findByProject($project) as $projectBoard) {
            if ($projectBoard->getId() !== $board->getId()) {
                $nextBoardId = $projectBoard->getId();
                break;
            }
        }

        $this->entityManager->remove($board);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'nextBoardId' => $nextBoardId,
        ]);
    }

    /**
     * @return array{id: int|null, title: string, updatedAt: string|null}
     */
    private function formatBoard(KanbanBoard $board, int $projectId): array
    {
        return [
            'id' => $board->getId(),
            'title' => $board->getTitle(),
            'updatedAt' => $board->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatColumn(KanbanColumn $col): array
    {
        $cards = [];
        foreach ($col->getCards() as $card) {
            if ($card->isArchived()) {
                continue;
            }

            $labels = [];
            foreach ($card->getLabels() as $lbl) {
                $labels[] = ['id' => $lbl->getId(), 'name' => $lbl->getName(), 'color' => $lbl->getColor()->value];
            }

            $assignees = [];
            foreach ($card->getAssignees() as $u) {
                $assignees[] = [
                    'id' => $u->getId(),
                    'name' => trim($u->getLastname() . ' ' . $u->getFirstname()) ?: (string) $u->getId(),
                ];
            }

            $cards[] = [
                'id' => $card->getId(),
                'title' => $card->getTitle(),
                'description' => $card->getDescription(),
                'position' => $card->getPosition(),
                'priority' => $card->getPriority()?->value,
                'dueDate' => $card->getDueDate()?->format(\DateTimeInterface::ATOM),
                'labels' => $labels,
                'assignees' => $assignees,
                'checklistTotal' => $card->getSubtasks()->count(),
                'checklistDone' => $card->getSubtasks()->filter(fn ($s) => $s->isCompleted())->count(),
                'commentsCount' => $card->getComments()->count(),
                'updatedAt' => $card->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return [
            'id' => $col->getId(),
            'title' => $col->getTitle(),
            'headerColor' => $col->getHeaderColor()->value,
            'position' => $col->getPosition(),
            'cards' => $cards,
        ];
    }
}
