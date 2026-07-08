<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Kanban;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Kanban\KanbanCard;
use App\Entity\Kanban\KanbanCardSubtask;
use App\Entity\Kanban\Project\KanbanProjectUser;
use App\Entity\User\User;
use App\Enum\Kanban\KanbanBoardMemberRole;
use App\Enum\Kanban\KanbanSubtaskStatus;
use App\Repository\Kanban\KanbanCardRepository;
use App\Repository\Kanban\KanbanChecklistItemRepository;
use App\Repository\Kanban\Project\KanbanProjectUserRepository;
use App\Repository\User\UserRepository;
use App\Service\Kanban\KanbanCardActivityLogger;
use App\Service\Kanban\KanbanRealtimePublisher;
use App\Service\Kanban\KanbanService;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

// DISABLED KANBAN MODULE
// #[Route('/spa/api/cards/{cardId}/subtasks')]
final class SubtaskController extends AbstractController
{
    public function __construct(
        private readonly KanbanCardRepository $cardRepo,
        private readonly KanbanChecklistItemRepository $subtaskRepo,
        private readonly KanbanService $kanbanService,
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepo,
        private readonly KanbanProjectUserRepository $projectUserRepo,
        private readonly NotificationService $notificationService,
        private readonly KanbanCardActivityLogger $activityLogger,
        private readonly KanbanRealtimePublisher $realtimePublisher,
    ) {
    }

    #[Route('', name: 'spa_api_cards_subtasks_list', requirements: ['cardId' => '\d+'], methods: ['GET'])]
    public function list(int $cardId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $card = $this->cardRepo->find($cardId);
        if ($card === null) {
            return $this->json(['error' => SpaApiError::CARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_VIEWER);

        $data = array_map(
            fn (KanbanCardSubtask $subtask): array => $this->formatSubtask($subtask),
            $card->getSubtasks()->toArray(),
        );

        return $this->json($data);
    }

    #[Route('', name: 'spa_api_cards_subtasks_create', requirements: ['cardId' => '\d+'], methods: ['POST'])]
    public function create(int $cardId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $card = $this->cardRepo->find($cardId);
        if ($card === null) {
            return $this->json(['error' => SpaApiError::CARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_EDITOR);

        $payload = json_decode($request->getContent(), true) ?? [];
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            return $this->json(['error' => SpaApiError::SUBTASK_TITLE_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $subtask = new KanbanCardSubtask();
        $subtask->setTitle($title);
        $subtask->setPosition($this->subtaskRepo->getMaxPosition($card) + 1.0);
        $subtask->setCard($card);

        $this->em->persist($subtask);
        $this->em->flush();

        $this->activityLogger->logSubtaskAdded($card, $subtask->getTitle());

        // Realtime: обновляем счётчики подзадач на карточке доски.
        $this->realtimePublisher->publishCardPatch(
            $card,
            $this->realtimePublisher->buildChecklistCounters($card),
            $user->getId(),
        );

        return $this->json($this->formatSubtask($subtask), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spa_api_cards_subtasks_update', requirements: ['cardId' => '\d+', 'id' => '\d+'], methods: ['PATCH'])]
    public function update(int $cardId, int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $card = $this->cardRepo->find($cardId);
        if ($card === null) {
            return $this->json(['error' => SpaApiError::CARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_EDITOR);

        $subtask = $this->subtaskRepo->find($id);
        if ($subtask === null || $subtask->getCard()->getId() !== $cardId) {
            return $this->json(['error' => SpaApiError::SUBTASK_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true) ?? [];

        $wasCompleted = $subtask->isCompleted();

        if (isset($payload['title']) && trim((string) $payload['title']) !== '') {
            $subtask->setTitle(trim((string) $payload['title']));
        }
        if (array_key_exists('status', $payload) && is_string($payload['status'])) {
            $status = KanbanSubtaskStatus::tryFrom($payload['status']);
            if ($status !== null) {
                $subtask->setStatus($status);
            }
        }
        if (array_key_exists('isCompleted', $payload)) {
            $subtask->setIsCompleted((bool) $payload['isCompleted']);
        }

        $oldSubtaskAssignee = $subtask->getUser();
        $assigneeAddedToProject = false;
        $assignedUser = null;
        if (array_key_exists('user_id', $payload)) {
            if ($payload['user_id'] === null) {
                $subtask->setUser(null);
            } else {
                $assignee = $this->userRepo->find((int) $payload['user_id']);
                if ($assignee === null) {
                    return $this->json(['error' => SpaApiError::USER_NOT_FOUND], Response::HTTP_NOT_FOUND);
                }

                $project = $card->getColumn()->getBoard()->getProject();
                $assigneeAddedToProject = $project !== null
                    && $this->projectUserRepo->findByProjectAndUser($project, $assignee) === null;
                if ($assigneeAddedToProject) {
                    $projectUser = new KanbanProjectUser();
                    $projectUser->setKanbanProject($project);
                    $projectUser->setUser($assignee);
                    $projectUser->setRole(KanbanBoardMemberRole::KANBAN_VIEWER);
                    $this->em->persist($projectUser);
                }
                $subtask->setUser($assignee);
                $assignedUser = $assignee;
            }
        }

        $this->em->flush();

        if ($subtask->isCompleted() !== $wasCompleted) {
            $this->activityLogger->logSubtaskCompleted($card, $subtask->getTitle(), $subtask->isCompleted());
            // Realtime: изменилось число выполненных — обновляем счётчики на карточке доски.
            // Смена только названия/исполнителя подзадачи счётчики не меняет, поэтому не публикуем.
            $this->realtimePublisher->publishCardPatch(
                $card,
                $this->realtimePublisher->buildChecklistCounters($card),
                $user->getId(),
            );
        }

        $newSubtaskAssignee = $subtask->getUser();
        if (($oldSubtaskAssignee?->getId()) !== ($newSubtaskAssignee?->getId())) {
            if ($oldSubtaskAssignee !== null) {
                $this->activityLogger->logSubtaskUnassigned($card, $subtask->getTitle(), $this->assigneeName($oldSubtaskAssignee));
            }
            if ($newSubtaskAssignee !== null) {
                $this->activityLogger->logSubtaskAssigned($card, $subtask->getTitle(), $this->assigneeName($newSubtaskAssignee));
            }
        }

        if ($assignedUser !== null && $assignedUser->getId() !== $user->getId()) {
            $board = $card->getColumn()->getBoard();
            $project = $board->getProject();
            $boardLink = $this->generateUrl('app_kanban_board', ['id' => $board->getId()]);
            if ($assigneeAddedToProject && $project !== null) {
                $projectLink = $this->generateUrl('app_kanban_project', ['id' => $project->getId()]);
                $this->notificationService->notifyNewKanbanProjectUser($assignedUser, $project->getName() ?? 'Проект', $projectLink);
            }
            $subtaskTitle = $subtask->getTitle() . ' (задача: ' . $card->getTitle() . ')';
            $this->notificationService->notifyKanbanTaskAssigned($assignedUser, $subtaskTitle, $boardLink, true);
        }

        return $this->json($this->formatSubtask($subtask));
    }

    #[Route('/{id}', name: 'spa_api_cards_subtasks_delete', requirements: ['cardId' => '\d+', 'id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $cardId, int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $card = $this->cardRepo->find($cardId);
        if ($card === null) {
            return $this->json(['error' => SpaApiError::CARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_EDITOR);

        $subtask = $this->subtaskRepo->find($id);
        if ($subtask === null || $subtask->getCard()->getId() !== $cardId) {
            return $this->json(['error' => SpaApiError::SUBTASK_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $subtaskTitle = $subtask->getTitle();

        // Снимаем подзадачу с карточки, чтобы in-memory коллекция (и счётчики ниже)
        // отражали состояние после удаления; orphanRemoval удалит запись из БД.
        $card->getSubtasks()->removeElement($subtask);
        $this->em->remove($subtask);
        $this->em->flush();

        $this->activityLogger->logSubtaskRemoved($card, $subtaskTitle);

        // Realtime: подзадача удалена — обновляем счётчики на карточке доски.
        $this->realtimePublisher->publishCardPatch(
            $card,
            $this->realtimePublisher->buildChecklistCounters($card),
            $user->getId(),
        );

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function assigneeName(User $u): string
    {
        return trim($u->getLastname() . ' ' . $u->getFirstname()) ?: ($u->getLogin() ?? (string) $u->getId());
    }

    /**
     * @return array{
     *     id: int|null,
     *     title: string,
     *     status: string,
     *     isCompleted: bool,
     *     position: float,
     *     userId: int|null,
     *     userName: string|null
     * }
     */
    private function formatSubtask(KanbanCardSubtask $subtask): array
    {
        $assignee = $subtask->getUser();

        return [
            'id' => $subtask->getId(),
            'title' => $subtask->getTitle(),
            'status' => $subtask->getStatus()->value,
            'isCompleted' => $subtask->isCompleted(),
            'position' => $subtask->getPosition(),
            'userId' => $assignee?->getId(),
            'userName' => $assignee !== null ? trim($assignee->getLastname() . ' ' . $assignee->getFirstname()) : null,
        ];
    }
}
