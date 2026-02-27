<?php

namespace App\Controller\Kanban;

use App\Entity\User\User;
use App\Enum\KanbanBoardMemberRole;
use App\Repository\Kanban\KanbanBoardRepository;
use App\Service\Kanban\KanbanService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectKanbanController extends AbstractController
{
    public function __construct(
        private readonly KanbanBoardRepository $boardRepo,
        private readonly KanbanService $kanbanService,
    ) {
    }

    #[Route('/kanban_boards', name: 'app_kanban_boards')]
    public function boardList(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $boards = $this->boardRepo->findByMember($user);

        return $this->render('kanban/board_list.html.twig', [
            'active_tab' => 'kanban_boards',
            'boards' => $boards,
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
            return $this->redirectToRoute('app_kanban_boards');
        }

        $board = $this->kanbanService->createBoard($title, $user);

        return $this->redirectToRoute('app_kanban_board', ['id' => $board->getId()]);
    }

    #[Route('/kanban_board/{id}', name: 'app_kanban_board')]
    public function kanbanBoard(string $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $board = $this->boardRepo->findOneWithRelations($id);
        if (!$board) {
            throw $this->createNotFoundException('Доска не найдена.');
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::VIEWER);

        $memberRole = $this->kanbanService->getMemberRole($board, $user);

        return $this->render('kanban/kanban_board.html.twig', [
            'active_tab' => 'kanban_boards',
            'board' => $board,
            'memberRole' => $memberRole,
            'currentUser' => $user,
        ]);
    }
}
