<?php

declare(strict_types=1);

namespace App\Controller\SpaApi;

use App\Entity\User\User;
use App\Enum\Kanban\KanbanBoardMemberRole;
use App\Repository\Kanban\KanbanBoardRepository;
use App\Repository\Kanban\Project\KanbanProjectRepository;
use App\Service\Kanban\KanbanService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class MeController extends AbstractController
{
    public function __construct(
        private readonly KanbanProjectRepository $projectRepository,
        private readonly KanbanBoardRepository $boardRepository,
        private readonly KanbanService $kanbanService,
    ) {
    }

    #[Route('/spa/api/me', name: 'spa_api_me', methods: ['GET'])]
    public function __invoke(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->json([
            'id' => $user->getId(),
            'login' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
            'lastname' => $user->getLastname(),
            'firstname' => $user->getFirstname(),
            'patronymic' => $user->getPatronymic(),
            'projects' => $this->buildProjectsList($user),
        ]);
    }

    /**
     * Список проектов — копия ProjectKanbanController::personalProjects (JSON для SPA).
     *
     * @return list<array{
     *     id: int,
     *     name: string,
     *     description: string|null,
     *     isOwner: bool,
     *     isProjectAdmin: bool,
     *     entryBoardId: int|null,
     * }>
     */
    private function buildProjectsList(User $user): array
    {
        $projects = $this->projectRepository->findByMemberWithAccessibleBoards($user);
        $projectIds = array_map(static fn ($p) => $p->getId(), $projects);

        $firstBoardsByProject = $this->boardRepository->findFirstBoardByProjectIds($projectIds);
        $boardsWithAssignedCards = $this->boardRepository->findAllByUserWithAssignedCards($user);
        $projectIdToFirstBoardWithCards = [];
        foreach ($boardsWithAssignedCards as $board) {
            $pid = $board->getProject()?->getId();
            if ($pid !== null && !isset($projectIdToFirstBoardWithCards[$pid])) {
                $projectIdToFirstBoardWithCards[$pid] = $board;
            }
        }

        $result = [];
        foreach ($projects as $project) {
            $pid = $project->getId();
            $firstBoard = $firstBoardsByProject[$pid] ?? null;
            $memberRole = $firstBoard
                ? $this->kanbanService->getMemberRole($firstBoard, $user)
                : KanbanBoardMemberRole::KANBAN_ADMIN;

            $isProjectAdmin = false;
            if ($memberRole === KanbanBoardMemberRole::KANBAN_ADMIN) {
                $isProjectAdmin = true;
                $entryBoard = $firstBoard ?? $projectIdToFirstBoardWithCards[$pid] ?? null;
            } else {
                $entryBoard = $projectIdToFirstBoardWithCards[$pid] ?? null;
            }

            $entryBoardId = $entryBoard?->getId();
            $result[] = [
                'id' => $pid,
                'name' => $project->getName() ?? 'Проект',
                'description' => $project->getDescription(),
                'isOwner' => $project->getOwner() === $user,
                'isProjectAdmin' => $isProjectAdmin,
                'entryBoardId' => $entryBoardId
            ];
        }

        return $result;
    }
}
