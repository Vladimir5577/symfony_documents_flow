<?php

namespace App\Controller\Kanban\Api;

use App\Repository\Kanban\Project\KanbanProjectRepository;
use App\Repository\Kanban\Project\KanbanProjectUserRepository;
use App\Repository\User\UserRepository;
use App\Service\Kanban\KanbanService;
use App\Enum\KanbanBoardMemberRole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/kanban/users')]
final class KanbanUserSearchApiController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly KanbanProjectRepository $projectRepo,
        private readonly KanbanProjectUserRepository $projectUserRepo,
        private readonly KanbanService $kanbanService,
    ) {
    }

    #[Route('/search', name: 'api_kanban_users_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = trim($request->query->getString('query', ''));
        $projectId = $request->query->getInt('project_id');

        if ($projectId > 0) {
            $project = $this->projectRepo->find($projectId);
            if (!$project) {
                return $this->json([]);
            }
            /** @var \App\Entity\User\User $user */
            $user = $this->getUser();
            $firstBoard = $project->getBoards()->first();
            if ($firstBoard) {
                $this->kanbanService->requireRole($firstBoard, $user, KanbanBoardMemberRole::VIEWER);
            } elseif ($project->getOwner() !== $user && !$this->projectUserRepo->findByProjectAndUser($project, $user)) {
                return $this->json([]);
            }
            $users = $this->projectUserRepo->findProjectMemberUsers($project, $query, 20);
        } else {
            $result = $this->userRepo->findPaginated(page: 1, limit: 20, search: $query);
            $users = $result['users'];
        }

        $data = [];
        foreach ($users as $u) {
            $data[] = ['id' => $u->getId(), 'name' => $u->getFirstname() . ' ' . $u->getLastname()];
        }

        return $this->json($data);
    }
}
