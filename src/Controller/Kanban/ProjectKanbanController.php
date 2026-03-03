<?php

namespace App\Controller\Kanban;

use App\Entity\Kanban\Project\KanbanProjectUser;
use App\Entity\User\User;
use App\Enum\Kanban\KanbanBoardMemberRole;
use App\Repository\Kanban\KanbanBoardRepository;
use App\Repository\Kanban\Project\KanbanProjectRepository;
use App\Repository\Kanban\Project\KanbanProjectUserRepository;
use App\Repository\Organization\OrganizationRepository;
use App\Repository\User\UserRepository;
use App\Service\Kanban\KanbanService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectKanbanController extends AbstractController
{
    public function __construct(
        private readonly KanbanBoardRepository $boardRepo,
        private readonly KanbanProjectRepository $projectRepo,
        private readonly KanbanProjectUserRepository $projectUserRepo,
        private readonly KanbanService $kanbanService,
    ) {
    }

    #[Route('/personal_projects', name: 'app_kanban_personal_projects')]
    public function personalProjects(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $projects = $this->projectRepo->findByMember($user);

        return $this->render('kanban/personal_projects.html.twig', [
            'active_tab' => 'kanban_boards',
            'projects' => $projects,
        ]);
    }

    #[Route('/kanban_board/create', name: 'app_kanban_board_create', methods: ['POST'], priority: 1)]
    public function createBoard(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $title = trim($request->request->get('title', ''));
        if ($title === '') {
            $this->addFlash('error', 'Название доски обязательно.');
            return $this->redirectToRoute('app_kanban_personal_projects');
        }

        $board = $this->kanbanService->createBoard($title, $user);

        return $this->redirectToRoute('app_kanban_board', ['id' => $board->getId()]);
    }

    #[Route('/kanban_board/{id}', name: 'app_kanban_board')]
    public function kanbanBoard(int $id, OrganizationRepository $organizationRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $board = $this->boardRepo->findOneWithRelations($id);
        if (!$board) {
            throw $this->createNotFoundException('Доска не найдена.');
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::KANBAN_VIEWER);

        $memberRole = $this->kanbanService->getMemberRole($board, $user);

        if ($memberRole !== KanbanBoardMemberRole::KANBAN_ADMIN && !$this->boardRepo->userHasAssignedCardsOnBoard($board, $user)) {
            throw $this->createAccessDeniedException('Нет доступа к этой доске: на вас не назначены задачи.');
        }

        $projectBoards = [];
        $organizations = [];
        if ($board->getProject() !== null) {
            $project = $board->getProject();
            if ($memberRole === KanbanBoardMemberRole::KANBAN_ADMIN) {
                $projectBoards = $this->boardRepo->findByProject($project);
            } else {
                $projectBoards = $this->boardRepo->findByProjectAndUserWithAssignedCards($project, $user);
            }
            $isAdmin = $this->isGranted('ROLE_ADMIN');
            $userOrganization = $user->getOrganization();
            $rootOrganization = $userOrganization && !$isAdmin ? $userOrganization->getRootOrganization() : null;
            $organizationTree = $organizationRepository->getOrganizationTree($isAdmin ? null : $rootOrganization);
            foreach ($organizationTree as $org) {
                $loadedOrg = $organizationRepository->findWithChildren($org->getId());
                if ($loadedOrg) {
                    $organizations[] = $loadedOrg;
                }
            }
            if ($organizations === [] && $userOrganization) {
                $loadedOrg = $organizationRepository->findWithChildren($userOrganization->getId());
                if ($loadedOrg) {
                    $organizations[] = $loadedOrg;
                }
            }
        }

        return $this->render('kanban/kanban_board.html.twig', [
            'active_tab' => 'kanban_boards',
            'board' => $board,
            'memberRole' => $memberRole,
            'currentUser' => $user,
            'projectBoards' => $projectBoards,
            'organizations' => $organizations,
        ]);
    }

    #[Route('/kanban_project/{id}/board_create', name: 'app_kanban_project_board_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function createBoardInProject(int $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $project = $this->projectRepo->find($id);
        if (!$project) {
            throw $this->createNotFoundException('Проект не найден.');
        }

        $firstBoard = $project->getBoards()->first();
        if ($firstBoard) {
            $this->kanbanService->requireRole($firstBoard, $user, KanbanBoardMemberRole::KANBAN_EDITOR);
        } elseif ($project->getOwner() !== $user && !$this->projectUserRepo->findByProjectAndUser($project, $user)) {
            throw $this->createAccessDeniedException('Нет доступа к проекту.');
        }

        if (!$this->isCsrfTokenValid('create_board_in_project', $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Неверный CSRF токен.');
            $redirectId = $firstBoard ? $firstBoard->getId() : $project->getId();
            return $this->redirect($firstBoard ? $this->generateUrl('app_kanban_board', ['id' => $redirectId]) : $this->generateUrl('app_kanban_project', ['id' => $redirectId]));
        }

        $title = trim((string) $request->request->get('title', ''));
        if ($title === '') {
            $this->addFlash('error', 'Название доски обязательно.');
            $redirectId = $firstBoard ? $firstBoard->getId() : $project->getId();
            return $this->redirect($firstBoard ? $this->generateUrl('app_kanban_board', ['id' => $redirectId]) : $this->generateUrl('app_kanban_project', ['id' => $redirectId]));
        }

        $columnsRaw = $request->request->all('columns');
        $columns = is_array($columnsRaw) ? array_values(array_filter(array_map('trim', $columnsRaw))) : [];
        if ($columns === []) {
            $columns = ['Backlog', 'To Do', 'In Progress', 'Done'];
        }

        $newBoard = $this->kanbanService->createBoard($project, $title, $user);
        foreach ($columns as $i => $columnTitle) {
            if ($columnTitle === '') {
                continue;
            }
            $this->kanbanService->createColumn($newBoard, $columnTitle);
        }

        $this->addFlash('success', 'Доска «' . $title . '» создана.');
        return $this->redirectToRoute('app_kanban_board', ['id' => $newBoard->getId()]);
    }

    #[Route('/kanban_project/{id}', name: 'app_kanban_project', requirements: ['id' => '\d+'])]
    public function viewProject(int $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $project = $this->projectRepo->find($id);
        if (!$project) {
            throw $this->createNotFoundException('Проект не найден.');
        }

        $board = $project->getBoards()->first();
        if ($board) {
            $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::KANBAN_VIEWER);
        } elseif ($project->getOwner() !== $user && !$this->projectUserRepo->findByProjectAndUser($project, $user)) {
            throw $this->createAccessDeniedException('Нет доступа к проекту.');
        }

        $projectUsers = $this->projectUserRepo->findByProject($project);

        return $this->render('kanban/view_project.html.twig', [
            'active_tab' => 'kanban_project',
            'project' => $project,
            'projectUsers' => $projectUsers,
        ]);
    }

    #[Route('/kanban_project/{id}/edit', name: 'app_kanban_edit_project', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editProject(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $project = $this->projectRepo->find($id);
        if (!$project) {
            throw $this->createNotFoundException('Проект не найден.');
        }

        $board = $project->getBoards()->first();
        if ($board) {
            $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::KANBAN_VIEWER);
        } elseif ($project->getOwner() !== $user && !$this->projectUserRepo->findByProjectAndUser($project, $user)) {
            throw $this->createAccessDeniedException('Нет доступа к проекту.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_kanban_project', $request->request->get('_csrf_token', ''))) {
                $this->addFlash('error', 'Неверный CSRF токен.');
                return $this->redirectToRoute('app_kanban_edit_project', ['id' => $id]);
            }
            $name = trim((string) ($request->request->get('name', '')));
            if ($name === '') {
                $this->addFlash('error', 'Название проекта обязательно.');
                return $this->render('kanban/edit_project.html.twig', [
                    'active_tab' => 'kanban_project',
                    'project' => $project,
                    'form_data' => $request->request->all(),
                ]);
            }
            $description = trim((string) ($request->request->get('description', '')));
            $project->setName($name);
            $project->setDescription($description === '' ? null : $description);
            $entityManager->flush();
            $this->addFlash('success', 'Проект обновлён.');
            return $this->redirectToRoute('app_kanban_project', ['id' => $project->getId()]);
        }

        return $this->render('kanban/edit_project.html.twig', [
            'active_tab' => 'kanban_project',
            'project' => $project,
            'form_data' => ['name' => $project->getName(), 'description' => $project->getDescription() ?? ''],
        ]);
    }

    #[Route('/kanban_project/{id}/edit_members', name: 'app_kanban_edit_project_members', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editProjectMembers(
        int $id,
        Request $request,
        OrganizationRepository $organizationRepository,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $project = $this->projectRepo->find($id);
        if (!$project) {
            throw $this->createNotFoundException('Проект не найден.');
        }

        $board = $project->getBoards()->first();
        if ($board) {
            $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::KANBAN_VIEWER);
        } elseif ($project->getOwner() !== $user && !$this->projectUserRepo->findByProjectAndUser($project, $user)) {
            throw $this->createAccessDeniedException('Нет доступа к проекту.');
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $userOrganization = $user->getOrganization();
        $rootOrganization = $userOrganization && !$isAdmin ? $userOrganization->getRootOrganization() : null;
        $organizationTree = $organizationRepository->getOrganizationTree($isAdmin ? null : $rootOrganization);
        $organizationsWithChildren = [];
        foreach ($organizationTree as $org) {
            $loadedOrg = $organizationRepository->findWithChildren($org->getId());
            if ($loadedOrg) {
                $organizationsWithChildren[] = $loadedOrg;
            }
        }
        if ($organizationsWithChildren === [] && $userOrganization) {
            $loadedOrg = $organizationRepository->findWithChildren($userOrganization->getId());
            if ($loadedOrg) {
                $organizationsWithChildren[] = $loadedOrg;
            }
        }

        $projectUsers = $this->projectUserRepo->findByProject($project);
        $currentMemberIds = array_map(fn (KanbanProjectUser $pu) => $pu->getUser()?->getId(), $projectUsers);
        $currentMemberIds = array_values(array_filter($currentMemberIds));
        $initialUsers = $userRepository->findByIds($currentMemberIds);

        $renderForm = function (array $formUserIds = [], array $users = []) use (
            $project,
            $organizationsWithChildren,
            $currentMemberIds,
            $userRepository,
        ): Response {
            $memberIds = $formUserIds !== [] ? $formUserIds : $currentMemberIds;
            if ($users === [] && $memberIds !== []) {
                $users = $userRepository->findByIds($memberIds);
            }
            $projectUsers = $this->projectUserRepo->findByProject($project);
            return $this->render('kanban/edit_project_mambers.html.twig', [
                'active_tab' => 'kanban_project',
                'project' => $project,
                'projectUsers' => $projectUsers,
                'organizations' => $organizationsWithChildren,
                'users' => $users,
            ]);
        };

        if (!$request->isMethod('POST')) {
            return $renderForm([], $initialUsers);
        }

        $formData = $request->request->all();
        if (!$this->isCsrfTokenValid('edit_project_members', $formData['_csrf_token'] ?? '')) {
            $this->addFlash('error', 'Неверный CSRF токен.');
            return $this->redirectToRoute('app_kanban_edit_project_members', ['id' => $id]);
        }

        $userIds = $request->request->all('user_ids');
        $userIds = is_array($userIds) ? array_values(array_filter(array_map('intval', $userIds), fn ($uid) => $uid > 0)) : [];
        $ownerId = $project->getOwner()?->getId();
        if ($ownerId && !in_array($ownerId, $userIds, true)) {
            $userIds[] = $ownerId;
        }
        $userIds = array_values(array_unique($userIds));
        sort($userIds);

        foreach ($projectUsers as $pu) {
            $entityManager->remove($pu);
        }
        $entityManager->flush();

        foreach ($userIds as $userId) {
            $memberUser = $userRepository->find($userId);
            if (!$memberUser) {
                continue;
            }
            $pu = new KanbanProjectUser();
            $pu->setKanbanProject($project);
            $pu->setUser($memberUser);
            $pu->setRole($memberUser->getId() === $ownerId ? KanbanBoardMemberRole::KANBAN_ADMIN : KanbanBoardMemberRole::KANBAN_EDITOR);
            $entityManager->persist($pu);
        }
        $entityManager->flush();

        $this->addFlash('success', 'Участники проекта обновлены.');
        return $this->redirectToRoute('app_kanban_project', ['id' => $project->getId()]);
    }

    #[Route('/kanban_create_project', name: 'app_kanban_create_project', methods: ['GET'])]
    public function kanbanCreateProject(
        OrganizationRepository $organizationRepository,
        UserRepository $userRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $userOrganization = $user->getOrganization();
        $rootOrganization = $userOrganization && !$isAdmin ? $userOrganization->getRootOrganization() : null;

        $organizationTree = $organizationRepository->getOrganizationTree($isAdmin ? null : $rootOrganization);
        $organizationsWithChildren = [];
        foreach ($organizationTree as $org) {
            $loadedOrg = $organizationRepository->findWithChildren($org->getId());
            if ($loadedOrg) {
                $organizationsWithChildren[] = $loadedOrg;
            }
        }
        if ($organizationsWithChildren === [] && $userOrganization) {
            $loadedOrg = $organizationRepository->findWithChildren($userOrganization->getId());
            if ($loadedOrg) {
                $organizationsWithChildren[] = $loadedOrg;
            }
        }

        return $this->render('kanban/create_project.html.twig', [
            'active_tab' => 'kanban_project',
            'organizations' => $organizationsWithChildren,
            'form_data' => [],
            'users' => [],
        ]);
    }

    #[Route('/kanban_project/create', name: 'app_kanban_project_create', methods: ['POST'])]
    public function createProject(
        Request $request,
        OrganizationRepository $organizationRepository,
        UserRepository $userRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $userOrganization = $user->getOrganization();
        $rootOrganization = $userOrganization && !$isAdmin ? $userOrganization->getRootOrganization() : null;
        $organizationTree = $organizationRepository->getOrganizationTree($isAdmin ? null : $rootOrganization);
        $organizationsWithChildren = [];
        foreach ($organizationTree as $org) {
            $loadedOrg = $organizationRepository->findWithChildren($org->getId());
            if ($loadedOrg) {
                $organizationsWithChildren[] = $loadedOrg;
            }
        }
        if ($organizationsWithChildren === [] && $userOrganization) {
            $loadedOrg = $organizationRepository->findWithChildren($userOrganization->getId());
            if ($loadedOrg) {
                $organizationsWithChildren[] = $loadedOrg;
            }
        }

        $renderForm = function (array $formData = [], array $users = []) use ($organizationsWithChildren): Response {
            return $this->render('kanban/create_project.html.twig', [
                'active_tab' => 'kanban_project',
                'organizations' => $organizationsWithChildren,
                'form_data' => $formData,
                'users' => $users,
            ]);
        };

        if (!$this->isCsrfTokenValid('create_kanban_project', $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Недействительный токен безопасности.');
            return $this->redirectToRoute('app_kanban_create_project');
        }

        $formData = $request->request->all();
        $name = trim((string) ($formData['name'] ?? ''));
        if ($name === '') {
            $this->addFlash('error', 'Название проекта обязательно.');
            $users = [];
            if (!empty($formData['user_ids'])) {
                $users = $userRepository->findByIds((array) $formData['user_ids']);
            }
            return $renderForm($formData, $users);
        }

        $description = trim((string) ($formData['description'] ?? ''));
        $description = $description === '' ? null : $description;

        $userIds = $request->request->all('user_ids');
        $userIds = is_array($userIds) ? array_values(array_filter(array_map('intval', $userIds), fn ($id) => $id > 0)) : [];

        $boardsConfig = $this->parseBoardsConfig($request->request->all('boards') ?? []);

        $board = $this->kanbanService->createProject($name, $description, $user, $userIds, $boardsConfig);
        $project = $board->getProject();

        $this->addFlash('success', 'Проект «' . $name . '» создан.');

        return $this->redirectToRoute('app_kanban_project', ['id' => $project->getId()]);
    }

    /**
     * @param array<int|string, mixed> $raw
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
                ? array_values(array_filter(array_map('trim', $item['columns'])))
                : [];
            $result[] = ['title' => $title, 'columns' => $columns];
        }
        return $result;
    }

    #[Route('/kanban/board/{id}/rename', name: 'app_kanban_board_rename', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function renameBoard(int $id, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $board = $this->boardRepo->find($id);
        if (!$board) {
            return $this->json(['success' => false, 'error' => 'Доска не найдена.'], 404);
        }

        try {
            $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::KANBAN_EDITOR);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => 'Недостаточно прав для редактирования.'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $newTitle = trim($data['title'] ?? '');

        if ($newTitle === '') {
            return $this->json(['success' => false, 'error' => 'Название доски не может быть пустым.'], 400);
        }

        if (mb_strlen($newTitle) > 200) {
            return $this->json(['success' => false, 'error' => 'Название доски слишком длинное (максимум 200 символов).'], 400);
        }

        $board->setTitle($newTitle);
        $entityManager->flush();

        return $this->json(['success' => true, 'title' => $newTitle]);
    }

    #[Route('/kanban/board/{id}/delete', name: 'app_kanban_board_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteBoard(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $board = $this->boardRepo->find($id);
        if (!$board) {
            return $this->json(['success' => false, 'error' => 'Доска не найдена.'], 404);
        }

        try {
            $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::KANBAN_ADMIN);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => 'Недостаточно прав для удаления доски.'], 403);
        }

        $project = $board->getProject();
        $redirectUrl = null;

        // Если доска принадлежит проекту, перенаправляем на другую доску проекта или на проект
        if ($project) {
            $projectBoards = $this->boardRepo->findByProject($project);
            $otherBoard = null;
            foreach ($projectBoards as $b) {
                if ($b->getId() !== $board->getId()) {
                    $otherBoard = $b;
                    break;
                }
            }

            if ($otherBoard) {
                $redirectUrl = $this->generateUrl('app_kanban_board', ['id' => $otherBoard->getId()]);
            } else {
                $redirectUrl = $this->generateUrl('app_kanban_project', ['id' => $project->getId()]);
            }
        } else {
            // Персональная доска - перенаправляем на список проектов
            $redirectUrl = $this->generateUrl('app_kanban_personal_projects');
        }

        $entityManager->remove($board);
        $entityManager->flush();

        return $this->json(['success' => true, 'redirectUrl' => $redirectUrl]);
    }
}
