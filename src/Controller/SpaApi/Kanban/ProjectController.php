<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Kanban;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Kanban\KanbanBoard;
use App\Entity\Kanban\Project\KanbanProject;
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

    #[Route('', name: 'spa_api_project_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => SpaApiError::PROJECT_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        if (mb_strlen($name) > 255) {
            return $this->json(
                ['error' => SpaApiError::PROJECT_NAME_TOO_LONG],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $descriptionRaw = $payload['description'] ?? null;
        $description = is_string($descriptionRaw) ? trim($descriptionRaw) : null;
        if ($description === '') {
            $description = null;
        }

        $memberUserIdsRaw = $payload['memberUserIds'] ?? [];
        $memberUserIds = is_array($memberUserIdsRaw)
            ? array_values(array_filter(array_map(static fn ($id) => (int) $id, $memberUserIdsRaw), static fn (int $id) => $id > 0))
            : [];

        $boardsConfig = $this->parseBoardsConfig(is_array($payload['boards'] ?? null) ? $payload['boards'] : []);

        $firstBoard = $this->kanbanService->createProject($name, $description, $user, $memberUserIds, $boardsConfig);
        $project = $firstBoard->getProject();
        $projectId = $project?->getId();
        if ($project === null || $projectId === null) {
            return $this->json(['error' => SpaApiError::PROJECT_CREATE_FAILED], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'id' => $projectId,
            'name' => $project->getName(),
            'description' => $project->getDescription(),
            'createdAt' => $project->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $project->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'isOwner' => true,
            'isProjectAdmin' => true,
            'entryBoardId' => $firstBoard->getId(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spa_api_project_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function view(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $project = $this->projectRepository->find($id);
        if ($project === null) {
            return $this->json(['error' => SpaApiError::PROJECT_NOT_FOUND], 404);
        }

        $projectId = $project->getId();
        if ($projectId === null) {
            return $this->json(['error' => SpaApiError::PROJECT_NOT_FOUND], 404);
        }

        $memberRole = KanbanBoardMemberRole::KANBAN_ADMIN;

        try {
            if ($project->getOwner() !== $user && $this->projectUserRepository->findByProjectAndUser($project, $user) === null) {
                return $this->json(['error' => SpaApiError::PROJECT_ACCESS_DENIED], 403);
            }
        } catch (AccessDeniedHttpException $e) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], 403);
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

    /**
     * Обновление проекта (как POST /kanban_project/{id}/edit в ProjectKanbanController).
     *
     * Редактируемые поля из edit_project.html.twig: name, description.
     * Участники и роли — отдельные эндпоинты (edit_members, change_member_role, remove_member).
     */
    #[Route('/{id}', name: 'spa_api_project_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
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

        try {
            $this->assertProjectAdmin($project, $user);
        } catch (AccessDeniedHttpException $e) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $hasName = array_key_exists('name', $payload);
        $hasDescription = array_key_exists('description', $payload);
        if (!$hasName && !$hasDescription) {
            return $this->json(
                ['error' => SpaApiError::UPDATE_FIELDS_REQUIRED],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($hasName) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                return $this->json(['error' => SpaApiError::PROJECT_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
            }
            if (mb_strlen($name) > 255) {
                return $this->json(
                    ['error' => SpaApiError::PROJECT_NAME_TOO_LONG],
                    Response::HTTP_BAD_REQUEST,
                );
            }
            $project->setName($name);
        }

        if ($hasDescription) {
            $descriptionRaw = $payload['description'];
            if ($descriptionRaw !== null && !is_string($descriptionRaw)) {
                return $this->json(['error' => SpaApiError::DESCRIPTION_INVALID_TYPE], Response::HTTP_BAD_REQUEST);
            }
            $description = is_string($descriptionRaw) ? trim($descriptionRaw) : null;
            $project->setDescription($description === '' ? null : $description);
        }

        $this->entityManager->flush();

        return $this->json([
            'id' => $projectId,
            'name' => $project->getName(),
            'description' => $project->getDescription(),
            'updatedAt' => $project->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{id}', name: 'spa_api_project_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $project = $this->projectRepository->find($id);
        if ($project === null) {
            return $this->json(['error' => SpaApiError::PROJECT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if ($project->getOwner() !== $user) {
            return $this->json(['error' => SpaApiError::INSUFFICIENT_PERMISSIONS], Response::HTTP_FORBIDDEN);
        }

        $project->setDeletedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json(['success' => true]);
    }

    /**
     * Права как ProjectKanbanController::editProject — только администратор проекта.
     *
     * @throws AccessDeniedHttpException
     */
    private function assertProjectAdmin(KanbanProject $project, User $user): void
    {
        $firstBoard = $project->getBoards()->first() ?: null;
        if ($firstBoard) {
            $this->kanbanService->requireRole($firstBoard, $user, KanbanBoardMemberRole::KANBAN_ADMIN);

            return;
        }

        if ($project->getOwner() !== $user && $this->projectUserRepository->findByProjectAndUser($project, $user) === null) {
            throw new AccessDeniedHttpException(SpaApiError::PROJECT_ACCESS_DENIED);
        }
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

    /**
     * @param array<int|string, mixed> $raw
     *
     * @return array<int, array{title: string, columns: array<int, string>}>
     */
    private function parseBoardsConfig(array $raw): array
    {
        $result = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            $columns = isset($item['columns']) && is_array($item['columns'])
                ? array_values(array_filter(array_map(static fn ($column) => trim((string) $column), $item['columns'])))
                : [];
            $result[] = ['title' => $title, 'columns' => $columns];
        }

        return $result;
    }
}
