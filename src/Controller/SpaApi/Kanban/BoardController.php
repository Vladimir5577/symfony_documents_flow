<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Kanban;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Kanban\KanbanBoard;
use App\Entity\Kanban\KanbanCard;
use App\Entity\Kanban\KanbanColumn;
use App\Entity\Kanban\Project\KanbanProject;
use App\Entity\User\User;
use App\Enum\Kanban\KanbanBoardMemberRole;
use App\Enum\Kanban\KanbanColumnColor;
use App\Repository\Kanban\KanbanBoardRepository;
use App\Repository\Kanban\KanbanCardRepository;
use App\Repository\Kanban\Project\KanbanProjectRepository;
use App\Service\Kanban\KanbanService;
use App\Service\User\UserAvatarUrlGenerator;
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
        private readonly KanbanCardRepository $cardRepository,
        private readonly UserAvatarUrlGenerator $userAvatarUrlGenerator,
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

    #[Route('/{id}/boards/{boardId}/archive', name: 'spa_api_project_board_archive', requirements: ['id' => '\d+', 'boardId' => '\d+'], methods: ['GET'])]
    public function boardArchive(int $id, int $boardId, Request $request, #[CurrentUser] ?User $user): JsonResponse
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

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::KANBAN_VIEWER);

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 10;

        $filters = [
            'title' => trim((string) $request->query->get('title', '')),
            'description' => trim((string) $request->query->get('description', '')),
            'dateFrom' => trim((string) $request->query->get('dateFrom', '')),
            'dateTo' => trim((string) $request->query->get('dateTo', '')),
        ];

        $pagination = $this->cardRepository->findArchivedByBoardPaginated($board, $page, $limit, $filters);

        $cards = [];
        foreach ($pagination['cards'] as $card) {
            $cards[] = $this->formatArchivedCard($card);
        }

        return $this->json([
            'cards' => $cards,
            'pagination' => [
                'currentPage' => $pagination['page'],
                'totalPages' => $pagination['totalPages'],
                'total' => $pagination['total'],
                'limit' => $pagination['limit'],
            ],
            'archivedCount' => $this->cardRepository->countArchivedByBoard($board),
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

        $columns = $this->parseColumnsForCreate($payload['columns'] ?? null);
        if ($columns === [] || $columns === null) {
            $columns = [
                ['title' => 'К выполнению', 'headerColor' => KanbanColumnColor::BG_SUCCESS],
                ['title' => 'В работе', 'headerColor' => KanbanColumnColor::BG_PRIMARY],
                ['title' => 'Сделаны', 'headerColor' => KanbanColumnColor::BG_WARNING],
                ['title' => 'Проверены', 'headerColor' => KanbanColumnColor::BG_DANGER],
            ];
        }

        $board = $this->kanbanService->createBoard($project, $title, $user);
        $defaultColors = [
            KanbanColumnColor::BG_SUCCESS,
            KanbanColumnColor::BG_PRIMARY,
            KanbanColumnColor::BG_WARNING,
            KanbanColumnColor::BG_DANGER,
        ];
        foreach ($columns as $i => $column) {
            $color = $column['headerColor']
                ?? ($defaultColors[$i % count($defaultColors)] ?? KanbanColumnColor::BG_PRIMARY);
            $this->kanbanService->createColumn($board, $column['title'], $color);
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

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::KANBAN_ADMIN);

        $payload = json_decode($request->getContent(), true) ?? [];
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        if (isset($payload['title']) && trim((string) $payload['title']) !== '') {
            $title = trim((string) $payload['title']);
            if (mb_strlen($title) > 200) {
                return $this->json(
                    ['error' => SpaApiError::BOARD_TITLE_TOO_LONG],
                    Response::HTTP_BAD_REQUEST,
                );
            }

            $board->setTitle($title);
        }

        if (isset($payload['position'])) {
            $board->setPosition((float) $payload['position']);
        }

        $this->entityManager->flush();

        if (isset($payload['position']) && $board->getProject() instanceof KanbanProject) {
            $this->rebalanceBoardPositionsIfNeeded($board->getProject());
        }

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

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::KANBAN_ADMIN);

        if ($this->cardRepository->countActiveCardsOnBoard($board) > 0) {
            return $this->json(['error' => SpaApiError::BOARD_HAS_CARDS], Response::HTTP_CONFLICT);
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

    /** Rebalance board positions if neighbours are too close (same as KanbanBoardApiController). */
    private function rebalanceBoardPositionsIfNeeded(KanbanProject $project): void
    {
        $boards = $this->boardRepository->createQueryBuilder('b')
            ->where('b.project = :project')
            ->setParameter('project', $project)
            ->orderBy('b.position', 'ASC')
            ->addOrderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();

        $needsRebalance = false;
        for ($i = 1, $count = count($boards); $i < $count; $i++) {
            if (abs($boards[$i]->getPosition() - $boards[$i - 1]->getPosition()) < 1e-5) {
                $needsRebalance = true;
                break;
            }
        }

        if ($needsRebalance) {
            $this->boardRepository->rebalancePositionsInProject($project);
            $this->entityManager->flush();
        }
    }

    /**
     * @return array<int, array{title: string, headerColor: ?KanbanColumnColor}>
     */
    private function parseColumnsForCreate(mixed $columnsRaw): array
    {
        if (!is_array($columnsRaw)) {
            return [];
        }

        $result = [];
        foreach ($columnsRaw as $column) {
            if (is_string($column)) {
                $title = trim($column);
                if ($title === '') {
                    continue;
                }
                $result[] = ['title' => $title, 'headerColor' => null];

                continue;
            }
            if (!is_array($column)) {
                continue;
            }

            $title = trim((string) ($column['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $headerColor = null;
            if (array_key_exists('headerColor', $column) && $column['headerColor'] !== null && $column['headerColor'] !== '') {
                $headerColor = KanbanColumnColor::tryFrom((string) $column['headerColor']);
            }

            $result[] = ['title' => $title, 'headerColor' => $headerColor];
        }

        return $result;
    }

    /**
     * @return array{id: int|null, title: string, position: float, updatedAt: string|null}
     */
    private function formatBoard(KanbanBoard $board, int $projectId): array
    {
        return [
            'id' => $board->getId(),
            'title' => $board->getTitle(),
            'position' => $board->getPosition(),
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
                $assignees[] = $this->formatAssignee($u);
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
                'commentsCount' => $card->getComments()->count()
                    + $card->getAttachments()->filter(fn ($att) => $att->getContext() === 'chat')->count(),
                'borderColor' => $card->getBorderColor(),
                'updatedAt' => $card->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
                'completedAt' => $card->getCompletedAt()?->format(\DateTimeInterface::ATOM),
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

    /**
     * @return array<string, mixed>
     */
    private function formatArchivedCard(KanbanCard $card): array
    {
        $archivedBy = $card->getArchivedBy();

        return [
            'id' => $card->getId(),
            'title' => $card->getTitle(),
            'description' => $card->getDescription(),
            'columnTitle' => $card->getColumn()->getTitle(),
            'borderColor' => $card->getBorderColor(),
            'archivedAt' => $card->getArchivedAt()?->format(\DateTimeInterface::ATOM),
            'archivedBy' => $archivedBy instanceof User ? $this->formatAssignee($archivedBy) : null,
        ];
    }

    /**
     * @return array{id: int|null, name: string, avatarUrl: string|null}
     */
    private function formatAssignee(User $user): array
    {
        return [
            'id' => $user->getId(),
            'name' => trim($user->getLastname() . ' ' . $user->getFirstname()) ?: (string) $user->getId(),
            'avatarUrl' => $this->userAvatarUrlGenerator->getAvatarUrl($user, UserAvatarUrlGenerator::FILTER_THUMBNAIL),
        ];
    }
}
