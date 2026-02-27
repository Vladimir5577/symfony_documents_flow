<?php

namespace App\Controller\Kanban\Api;

use App\Entity\Kanban\KanbanBoard;
use App\Entity\Kanban\KanbanBoardMember;
use App\Entity\User\User;
use App\Enum\KanbanBoardMemberRole;
use App\Repository\Kanban\KanbanBoardRepository;
use App\Service\Kanban\KanbanService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/kanban/boards')]
final class KanbanBoardApiController extends AbstractController
{
    public function __construct(
        private readonly KanbanBoardRepository $boardRepo,
        private readonly KanbanService $kanbanService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_kanban_boards_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $boards = $this->boardRepo->findByMember($user);

        $data = array_map(fn(KanbanBoard $b) => [
            'id' => (string) $b->getId(),
            'title' => $b->getTitle(),
            'createdAt' => $b->getCreatedAt()?->format('c'),
            'updatedAt' => $b->getUpdatedAt()?->format('c'),
        ], $boards);

        return $this->json($data);
    }

    #[Route('', name: 'api_kanban_boards_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $payload = json_decode($request->getContent(), true) ?? [];
        $title = trim($payload['title'] ?? '');
        if ($title === '') {
            return $this->json(['error' => 'Название обязательно.'], Response::HTTP_BAD_REQUEST);
        }

        $board = $this->kanbanService->createBoard($title, $user);

        return $this->json([
            'id' => (string) $board->getId(),
            'title' => $board->getTitle(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_kanban_boards_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $board = $this->boardRepo->findOneWithRelations($id);
        if (!$board) {
            return $this->json(['error' => 'Доска не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::VIEWER);

        $columns = [];
        foreach ($board->getColumns() as $col) {
            $cards = [];
            foreach ($col->getCards() as $card) {
                if ($card->isArchived()) {
                    continue;
                }
                $labels = [];
                foreach ($card->getLabels() as $lbl) {
                    $labels[] = [
                        'id' => (string) $lbl->getId(),
                        'name' => $lbl->getName(),
                        'color' => $lbl->getColor()->value,
                    ];
                }
                $checklistTotal = $card->getChecklistItems()->count();
                $checklistDone = $card->getChecklistItems()->filter(fn($ci) => $ci->isCompleted())->count();

                $cards[] = [
                    'id' => (string) $card->getId(),
                    'title' => $card->getTitle(),
                    'description' => $card->getDescription(),
                    'position' => $card->getPosition(),
                    'priority' => $card->getPriority()?->value,
                    'priorityLabel' => $card->getPriority()?->getLabel(),
                    'priorityColor' => $card->getPriority()?->getColor(),
                    'dueAt' => $card->getDueAt()?->format('c'),
                    'labels' => $labels,
                    'checklistTotal' => $checklistTotal,
                    'checklistDone' => $checklistDone,
                    'commentsCount' => $card->getComments()->count(),
                    'createdAt' => $card->getCreatedAt()?->format('c'),
                    'updatedAt' => $card->getUpdatedAt()?->format('c'),
                ];
            }
            $columns[] = [
                'id' => (string) $col->getId(),
                'title' => $col->getTitle(),
                'headerColor' => $col->getHeaderColor()->value,
                'position' => $col->getPosition(),
                'cards' => $cards,
            ];
        }

        return $this->json([
            'id' => (string) $board->getId(),
            'title' => $board->getTitle(),
            'columns' => $columns,
        ]);
    }

    #[Route('/{id}', name: 'api_kanban_boards_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $board = $this->boardRepo->find($id);
        if (!$board) {
            return $this->json(['error' => 'Доска не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::ADMIN);

        $payload = json_decode($request->getContent(), true) ?? [];
        if (isset($payload['title']) && trim($payload['title']) !== '') {
            $board->setTitle(trim($payload['title']));
        }

        $this->em->flush();

        return $this->json(['id' => (string) $board->getId(), 'title' => $board->getTitle()]);
    }

    #[Route('/{id}', name: 'api_kanban_boards_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $board = $this->boardRepo->find($id);
        if (!$board) {
            return $this->json(['error' => 'Доска не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::ADMIN);

        $this->em->remove($board);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/members', name: 'api_kanban_boards_add_member', methods: ['POST'])]
    public function addMember(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $board = $this->boardRepo->find($id);
        if (!$board) {
            return $this->json(['error' => 'Доска не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::ADMIN);

        $payload = json_decode($request->getContent(), true) ?? [];
        $userId = $payload['user_id'] ?? null;
        $role = KanbanBoardMemberRole::tryFrom($payload['role'] ?? '');

        if (!$userId || !$role) {
            return $this->json(['error' => 'user_id и role обязательны.'], Response::HTTP_BAD_REQUEST);
        }

        $targetUser = $this->em->getRepository(User::class)->find($userId);
        if (!$targetUser) {
            return $this->json(['error' => 'Пользователь не найден.'], Response::HTTP_NOT_FOUND);
        }

        $member = new KanbanBoardMember();
        $member->setBoard($board);
        $member->setUser($targetUser);
        $member->setRole($role);

        $this->em->persist($member);
        $this->em->flush();

        return $this->json([
            'id' => (string) $member->getId(),
            'role' => $member->getRole()->value,
        ], Response::HTTP_CREATED);
    }
}
