<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Kanban;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Kanban\Project\KanbanProject;
use App\Entity\Kanban\Project\KanbanProjectUser;
use App\Entity\User\User;
use App\Enum\Kanban\KanbanBoardMemberRole;
use App\Repository\Kanban\KanbanCardRepository;
use App\Repository\Kanban\KanbanChecklistItemRepository;
use App\Repository\Kanban\Project\KanbanProjectRepository;
use App\Repository\Kanban\Project\KanbanProjectUserRepository;
use App\Repository\User\UserRepository;
use App\Service\Kanban\KanbanService;
use App\Service\Notification\NotificationService;
use App\Service\User\UserAvatarUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/projects')]
final class ProjectMemberController extends AbstractController
{
    public function __construct(
        private readonly KanbanProjectRepository $projectRepository,
        private readonly KanbanProjectUserRepository $projectUserRepository,
        private readonly KanbanCardRepository $cardRepository,
        private readonly KanbanChecklistItemRepository $checklistRepository,
        private readonly UserRepository $userRepository,
        private readonly KanbanService $kanbanService,
        private readonly NotificationService $notificationService,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserAvatarUrlGenerator $userAvatarUrlGenerator,
    ) {
    }

    /**
     * Массовое обновление участников (как POST /kanban_project/{id}/edit_members).
     *
     * Body: { "members": [{ "userId": 2, "role": "KANBAN_EDITOR" }, ...] }
     * Владелец проекта всегда включается с ролью KANBAN_ADMIN.
     */
    #[Route('/{id}/members', name: 'spa_api_project_members_replace', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function replaceMembers(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $project = $this->findProject($id);
        if ($project === null) {
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

        $membersRaw = $payload['members'] ?? null;
        if (!is_array($membersRaw)) {
            return $this->json(
                ['error' => SpaApiError::MEMBERS_ARRAY_EXPECTED],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $owner = $project->getOwner();
        $ownerId = $owner?->getId();

        $projectUsers = $this->projectUserRepository->findByProject($project);
        $currentMemberIds = array_values(array_filter(array_map(
            static fn (KanbanProjectUser $pu) => $pu->getUser()?->getId(),
            $projectUsers,
        )));

        /** @var array<int, KanbanBoardMemberRole> $userIdToRole */
        $userIdToRole = [];
        foreach ($membersRaw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $memberUserId = (int) ($item['userId'] ?? $item['user_id'] ?? 0);
            if ($memberUserId <= 0) {
                continue;
            }
            if ($ownerId !== null && $memberUserId === $ownerId) {
                $userIdToRole[$memberUserId] = KanbanBoardMemberRole::KANBAN_ADMIN;
                continue;
            }
            $roleValue = trim((string) ($item['role'] ?? ''));
            $role = $roleValue !== '' ? KanbanBoardMemberRole::tryFrom($roleValue) : null;
            if ($role === null) {
                return $this->json(
                    ['error' => SpaApiError::INVALID_ROLE_FOR_USER, 'userId' => $memberUserId],
                    Response::HTTP_BAD_REQUEST,
                );
            }
            $userIdToRole[$memberUserId] = $role;
        }

        if ($ownerId !== null) {
            $userIdToRole[$ownerId] = KanbanBoardMemberRole::KANBAN_ADMIN;
        }

        if ($userIdToRole === []) {
            return $this->json(['error' => SpaApiError::MEMBERS_LIST_EMPTY], Response::HTTP_BAD_REQUEST);
        }

        $userIds = array_keys($userIdToRole);
        sort($userIds);

        foreach ($projectUsers as $projectUser) {
            $this->entityManager->remove($projectUser);
        }
        $this->entityManager->flush();

        $newMemberUserIds = array_diff($userIds, $currentMemberIds);
        $projectLink = $this->spaProjectEditPath($project);

        foreach ($userIds as $memberUserId) {
            $memberUser = $this->userRepository->find($memberUserId);
            if (!$memberUser instanceof User) {
                return $this->json(
                    ['error' => SpaApiError::USER_NOT_FOUND, 'userId' => $memberUserId],
                    Response::HTTP_BAD_REQUEST,
                );
            }

            $projectUser = new KanbanProjectUser();
            $projectUser->setKanbanProject($project);
            $projectUser->setUser($memberUser);
            $projectUser->setRole($userIdToRole[$memberUserId]);
            $this->entityManager->persist($projectUser);
        }
        $this->entityManager->flush();

        foreach ($newMemberUserIds as $newUserId) {
            $newMember = $this->userRepository->find($newUserId);
            if ($newMember instanceof User && $newMember->getId() !== $user->getId()) {
                $this->notificationService->notifyNewKanbanProjectUser(
                    $newMember,
                    $project->getName() ?? 'Проект',
                    $projectLink,
                );
            }
        }

        return $this->json([
            'members' => $this->formatMembers($project),
        ]);
    }

    /** Смена роли участника (как POST /kanban_project/{id}/change_member_role). */
    #[Route(
        '/{id}/members/{userId}',
        name: 'spa_api_project_member_update_role',
        requirements: ['id' => '\d+', 'userId' => '\d+'],
        methods: ['PATCH'],
    )]
    public function updateMemberRole(
        int $id,
        int $userId,
        Request $request,
        #[CurrentUser] ?User $currentUser,
    ): JsonResponse {
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $project = $this->findProject($id);
        if ($project === null) {
            return $this->json(['error' => SpaApiError::PROJECT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->assertProjectAdmin($project, $currentUser);
        } catch (AccessDeniedHttpException $e) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $owner = $project->getOwner();
        if ($owner !== null && $owner->getId() === $userId) {
            return $this->json(['error' => SpaApiError::OWNER_ROLE_IMMUTABLE], Response::HTTP_BAD_REQUEST);
        }

        $memberUser = $this->userRepository->find($userId);
        if (!$memberUser instanceof User) {
            return $this->json(['error' => SpaApiError::MEMBER_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $projectUser = $this->projectUserRepository->findByProjectAndUser($project, $memberUser);
        if ($projectUser === null) {
            return $this->json(['error' => SpaApiError::USER_NOT_PROJECT_MEMBER], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $roleValue = trim((string) ($payload['role'] ?? ''));
        $newRole = $roleValue !== '' ? KanbanBoardMemberRole::tryFrom($roleValue) : null;
        if ($newRole === null) {
            return $this->json(['error' => SpaApiError::INVALID_ROLE], Response::HTTP_BAD_REQUEST);
        }

        $projectUser->setRole($newRole);
        $this->entityManager->flush();

        return $this->json([
            'member' => $this->formatMember($projectUser, $owner),
        ]);
    }

    /** Исключение участника (как POST /kanban_project/{id}/remove_member). */
    #[Route(
        '/{id}/members/{userId}',
        name: 'spa_api_project_member_remove',
        requirements: ['id' => '\d+', 'userId' => '\d+'],
        methods: ['DELETE'],
    )]
    public function removeMember(int $id, int $userId, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $project = $this->findProject($id);
        if ($project === null) {
            return $this->json(['error' => SpaApiError::PROJECT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->assertProjectAdmin($project, $currentUser);
        } catch (AccessDeniedHttpException $e) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        if ($userId === $currentUser->getId()) {
            return $this->json(['error' => SpaApiError::CANNOT_REMOVE_SELF], Response::HTTP_BAD_REQUEST);
        }

        $owner = $project->getOwner();
        if ($owner !== null && $owner->getId() === $userId) {
            return $this->json(['error' => SpaApiError::CANNOT_REMOVE_OWNER], Response::HTTP_BAD_REQUEST);
        }

        $memberUser = $this->userRepository->find($userId);
        if (!$memberUser instanceof User) {
            return $this->json(['error' => SpaApiError::MEMBER_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $projectUser = $this->projectUserRepository->findByProjectAndUser($project, $memberUser);
        if ($projectUser === null) {
            return $this->json(['error' => SpaApiError::USER_NOT_PROJECT_MEMBER], Response::HTTP_NOT_FOUND);
        }

        foreach ($this->cardRepository->findCardsInProjectWithAssignee($project, $memberUser) as $card) {
            $card->removeAssignee($memberUser);
        }

        foreach ($this->checklistRepository->findSubtasksInProjectWithUser($project, $memberUser) as $subtask) {
            $subtask->setUser(null);
        }

        $this->entityManager->remove($projectUser);
        $this->entityManager->flush();

        $this->notificationService->notifyUserRemovedFromKanbanProject(
            $memberUser,
            $project->getName() ?? 'Проект',
            $this->spaProjectEditPath($project),
        );

        return $this->json(['success' => true]);
    }

    private function findProject(int $id): ?KanbanProject
    {
        return $this->projectRepository->find($id);
    }

    /**
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

    private function spaProjectEditPath(KanbanProject $project): string
    {
        $projectId = $project->getId();

        return $projectId !== null ? '/projects/' . $projectId . '/edit' : '/projects';
    }

    /**
     * @return list<array{
     *     userId: int|null,
     *     login: string|null,
     *     lastname: string|null,
     *     firstname: string|null,
     *     patronymic: string|null,
     *     profession: string|null,
     *     avatarUrl: string|null,
     *     role: string|null,
     *     roleLabel: string|null,
     *     isOwner: bool
     * }>
     */
    private function formatMembers(KanbanProject $project): array
    {
        $owner = $project->getOwner();
        $projectUsers = $this->projectUserRepository->findByProject($project);

        return array_map(
            fn (KanbanProjectUser $projectUser) => $this->formatMember($projectUser, $owner),
            $projectUsers,
        );
    }

    /**
     * @return array{
     *     userId: int|null,
     *     login: string|null,
     *     lastname: string|null,
     *     firstname: string|null,
     *     patronymic: string|null,
     *     profession: string|null,
     *     avatarUrl: string|null,
     *     role: string|null,
     *     roleLabel: string|null,
     *     isOwner: bool
     * }
     */
    private function formatMember(KanbanProjectUser $projectUser, ?User $owner): array
    {
        $member = $projectUser->getUser();
        $role = $projectUser->getRole();

        return [
            'userId' => $member?->getId(),
            'login' => $member?->getLogin(),
            'lastname' => $member?->getLastname(),
            'firstname' => $member?->getFirstname(),
            'patronymic' => $member?->getPatronymic(),
            'profession' => $member?->getWorker()?->getProfession(),
            'avatarUrl' => $member !== null
                ? $this->userAvatarUrlGenerator->getAvatarUrl($member, UserAvatarUrlGenerator::FILTER_THUMBNAIL)
                : null,
            'role' => $role?->value,
            'roleLabel' => $role?->getLabel(),
            'isOwner' => $owner !== null && $member === $owner,
        ];
    }
}
