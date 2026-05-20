<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Project;

use App\Entity\Kanban\KanbanBoard;
use App\Entity\Kanban\Project\KanbanProjectUser;
use App\Entity\User\User;
use App\Enum\Kanban\KanbanBoardMemberRole;
use App\Repository\Kanban\KanbanBoardRepository;
use App\Repository\Kanban\Project\KanbanProjectRepository;
use App\Repository\Kanban\Project\KanbanProjectUserRepository;
use App\Service\Kanban\KanbanService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/projects')]
final class ProjectController extends AbstractController
{
    public function __construct(
        private readonly KanbanProjectRepository $projectRepository,
        private readonly KanbanProjectUserRepository $projectUserRepository,
        private readonly KanbanBoardRepository $boardRepository,
        private readonly KanbanService $kanbanService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/{id}', name: 'spa_api_project_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function view(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $project = $this->projectRepository->find($id);
        if ($project === null) {
            return $this->json(['error' => 'Проект не найден'], 404);
        }

        $projectId = $project->getId();
        if ($projectId === null) {
            return $this->json(['error' => 'Проект не найден'], 404);
        }

        $firstBoard = $project->getBoards()->first() ?: null;
        $memberRole = KanbanBoardMemberRole::KANBAN_ADMIN;

        try {
            if ($firstBoard) {
                $this->kanbanService->requireRole($firstBoard, $user, KanbanBoardMemberRole::KANBAN_VIEWER);
                $memberRole = $this->kanbanService->getMemberRole($firstBoard, $user) ?? KanbanBoardMemberRole::KANBAN_VIEWER;

                if ($memberRole !== KanbanBoardMemberRole::KANBAN_ADMIN) {
                    return $this->json([
                        'error' => 'Нет доступа к странице проекта',
                        'entryBoardId' => $firstBoard->getId(),
                    ], 403);
                }
            } elseif ($project->getOwner() !== $user && $this->projectUserRepository->findByProjectAndUser($project, $user) === null) {
                return $this->json(['error' => 'Нет доступа к проекту'], 403);
            }
        } catch (AccessDeniedHttpException $e) {
            return $this->json(['error' => $e->getMessage()], 403);
        }

        $owner = $project->getOwner();
        $boards = $this->boardRepository->findByProject($project);
        $projectUsers = $this->projectUserRepository->findByProject($project);

        return $this->json([
            'id' => $projectId,
            'name' => $project->getName(),
            'description' => $project->getDescription(),
            'createdAt' => $project->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $project->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'owner' => $this->formatUser($owner),
            'isOwner' => $owner === $user,
            'isProjectAdmin' => $memberRole === KanbanBoardMemberRole::KANBAN_ADMIN,
            'memberRole' => $memberRole->value,
            'boards' => array_map(
                fn ($board) => $this->formatBoard($board, $projectId),
                $boards,
            ),
            'members' => array_map(
                function (KanbanProjectUser $projectUser) use ($owner): array {
                    $member = $projectUser->getUser();
                    $role = $projectUser->getRole();

                    return [
                        'userId' => $member?->getId(),
                        'login' => $member?->getLogin(),
                        'lastname' => $member?->getLastname(),
                        'firstname' => $member?->getFirstname(),
                        'patronymic' => $member?->getPatronymic(),
                        'profession' => $member?->getWorker()?->getProfession(),
                        'role' => $role?->value,
                        'roleLabel' => $role?->getLabel(),
                        'isOwner' => $owner !== null && $member === $owner,
                    ];
                },
                $projectUsers,
            ),
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
            return $this->json(['error' => 'Проект не найден'], Response::HTTP_NOT_FOUND);
        }

        $projectId = $project->getId();
        if ($projectId === null) {
            return $this->json(['error' => 'Проект не найден'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Некорректный JSON'], Response::HTTP_BAD_REQUEST);
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            return $this->json(['error' => 'Название доски обязательно'], Response::HTTP_BAD_REQUEST);
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
            return $this->json(['error' => 'Проект не найден'], Response::HTTP_NOT_FOUND);
        }

        $projectId = $project->getId();
        if ($projectId === null) {
            return $this->json(['error' => 'Проект не найден'], Response::HTTP_NOT_FOUND);
        }

        $board = $this->boardRepository->find($boardId);
        if ($board === null || $board->getProject()?->getId() !== $projectId) {
            return $this->json(['error' => 'Доска не найдена'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Некорректный JSON'], Response::HTTP_BAD_REQUEST);
        }

        if (!array_key_exists('title', $payload)) {
            return $this->json(['error' => 'Название доски обязательно'], Response::HTTP_BAD_REQUEST);
        }

        $title = trim((string) $payload['title']);
        if ($title === '') {
            return $this->json(['error' => 'Название доски обязательно'], Response::HTTP_BAD_REQUEST);
        }

        if (mb_strlen($title) > 200) {
            return $this->json(
                ['error' => 'Название доски слишком длинное (максимум 200 символов)'],
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
            return $this->json(['error' => 'Проект не найден'], Response::HTTP_NOT_FOUND);
        }

        $board = $this->boardRepository->find($boardId);
        if ($board === null || $board->getProject()?->getId() !== $project->getId()) {
            return $this->json(['error' => 'Доска не найдена'], Response::HTTP_NOT_FOUND);
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
     * @return array{
     *     id: int|null,
     *     title: string,
     *     updatedAt: string|null
     * }
     */
    private function formatBoard(KanbanBoard $board, int $projectId): array
    {
        $boardId = $board->getId();

        return [
            'id' => $boardId,
            'title' => $board->getTitle(),
            'updatedAt' => $board->getUpdatedAt()?->format(\DateTimeInterface::ATOM)
        ];
    }

    /**
     * @return array{
     *     id: int|null,
     *     login: string|null,
     *     lastname: string|null,
     *     firstname: string|null,
     *     patronymic: string|null
     * }|null
     */
    private function formatUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->getId(),
            'login' => $user->getLogin(),
            'lastname' => $user->getLastname(),
            'firstname' => $user->getFirstname(),
            'patronymic' => $user->getPatronymic(),
        ];
    }
}
