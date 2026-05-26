<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Kanban;

use App\Entity\User\User;
use App\Enum\Kanban\KanbanBoardMemberRole;
use App\Enum\Kanban\KanbanCardPriority;
use App\Repository\Kanban\KanbanCardRepository;
use App\Repository\Kanban\KanbanColumnRepository;
use App\Repository\Kanban\Project\KanbanProjectUserRepository;
use App\Repository\User\UserRepository;
use App\Service\Kanban\KanbanAttachmentPreviewUrlGenerator;
use App\Service\Kanban\KanbanService;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/cards')]
final class CardController extends AbstractController
{
    public function __construct(
        private readonly KanbanCardRepository $cardRepo,
        private readonly KanbanColumnRepository $columnRepo,
        private readonly KanbanProjectUserRepository $projectUserRepo,
        private readonly KanbanService $kanbanService,
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepo,
        private readonly NotificationService $notificationService,
        private readonly KanbanAttachmentPreviewUrlGenerator $kanbanAttachmentPreviewUrlGenerator,
    ) {
    }

    #[Route('', name: 'spa_api_cards_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $columnId = $payload['column_id'] ?? null;
        $title = trim($payload['title'] ?? '');

        if (!$columnId || $title === '') {
            return $this->json(['error' => 'column_id и title обязательны.'], Response::HTTP_BAD_REQUEST);
        }

        $column = $this->columnRepo->find($columnId);
        if (!$column) {
            return $this->json(['error' => 'Колонка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($column->getBoard(), $user, KanbanBoardMemberRole::KANBAN_EDITOR);

        $card = $this->kanbanService->createCard($column, $title, $user);

        $board = $column->getBoard();
        $project = $board->getProject();
        if ($project) {
            $admins = $this->projectUserRepo->findAdminUsersByProject($project);
            $admins = array_filter($admins, fn (User $u) => $u->getId() !== $user->getId());
            if ($admins) {
                $boardLink = $this->generateUrl('app_kanban_board', ['id' => $board->getId()]);
                $this->notificationService->notifyKanbanCardCreated(
                    array_values($admins),
                    $card->getTitle(),
                    $board->getTitle(),
                    $boardLink,
                );
            }
        }

        return $this->json([
            'id' => $card->getId(),
            'title' => $card->getTitle(),
            'position' => $card->getPosition(),
            'columnId' => $column->getId(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spa_api_cards_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $card = $this->cardRepo->findOneWithRelations($id);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_VIEWER);

        $subtasks = [];
        foreach ($card->getSubtasks() as $ci) {
            $subtasks[] = [
                'id' => $ci->getId(),
                'title' => $ci->getTitle(),
                'status' => $ci->getStatus()->value,
                'isCompleted' => $ci->isCompleted(),
                'position' => $ci->getPosition(),
                'userId' => $ci->getUser()?->getId(),
                'userName' => $ci->getUser() ? trim($ci->getUser()->getLastname() . ' ' . $ci->getUser()->getFirstname()) : null,
            ];
        }

        $comments = [];
        foreach ($card->getComments() as $com) {
            $comments[] = [
                'id' => $com->getId(),
                'body' => $com->getBody(),
                'authorName' => $com->getAuthor()->getLastname() . ' ' . $com->getAuthor()->getFirstname(),
                'authorId' => $com->getAuthor()->getId(),
                'createdAt' => $com->getCreatedAt()?->format('c'),
                'updatedAt' => $com->getUpdatedAt()?->format('c'),
            ];
        }

        $attachments = [];
        foreach ($card->getAttachments() as $att) {
            $attachments[] = [
                'id' => $att->getId(),
                'filename' => $att->getFilename(),
                'contentType' => $att->getContentType(),
                'sizeBytes' => $att->getSizeBytes(),
                'context' => $att->getContext(),
                'createdAt' => $att->getCreatedAt()?->format('c'),
                'previewUrl' => $this->kanbanAttachmentPreviewUrlGenerator->getPreviewUrl($att),
                'authorId' => $att->getAuthor()?->getId(),
                'authorName' => $att->getAuthor()?->getLastname() . ' ' . $att->getAuthor()?->getFirstname(),
            ];
        }

        $labels = [];
        foreach ($card->getLabels() as $lbl) {
            $labels[] = [
                'id' => $lbl->getId(),
                'name' => $lbl->getName(),
                'color' => $lbl->getColor()->value,
            ];
        }

        $assignees = [];
        foreach ($card->getAssignees() as $u) {
            $assignees[] = [
                'id' => $u->getId(),
                'name' => trim($u->getLastname() . ' ' . $u->getFirstname()) ?: (string) $u->getId(),
                'firstname' => $u->getFirstname(),
                'lastname' => $u->getLastname(),
            ];
        }

        return $this->json([
            'id' => $card->getId(),
            'title' => $card->getTitle(),
            'description' => $card->getDescription(),
            'position' => $card->getPosition(),
            'priority' => $card->getPriority()?->value,
            'priorityLabel' => $card->getPriority()?->getLabel(),
            'priorityColor' => $card->getPriority()?->getColor(),
            'dueDate' => $card->getDueDate()?->format('c'),
            'isArchived' => $card->isArchived(),
            'columnId' => $card->getColumn()->getId(),
            'columnTitle' => $card->getColumn()->getTitle(),
            'boardId' => $card->getColumn()->getBoard()->getId(),
            'subtasks' => $subtasks,
            'comments' => $comments,
            'attachments' => $attachments,
            'labels' => $labels,
            'assignees' => $assignees,
            'borderColor' => $card->getBorderColor(),
            'createdAt' => $card->getCreatedAt()?->format('c'),
            'updatedAt' => $card->getUpdatedAt()?->format('c'),
            'createdBy' => $card->getCreatedBy() ? [
                'id' => $card->getCreatedBy()->getId(),
                'firstname' => $card->getCreatedBy()->getFirstname(),
                'lastname' => $card->getCreatedBy()->getLastname(),
            ] : null,
        ]);
    }

    #[Route('/{id}', name: 'spa_api_cards_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $card = $this->cardRepo->find($id);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $board = $card->getColumn()->getBoard();
        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::KANBAN_EDITOR);

        $payload = json_decode($request->getContent(), true) ?? [];
        $memberRole = $this->kanbanService->getMemberRole($board, $user);

        if (isset($payload['title']) && trim($payload['title']) !== '') {
            if ($memberRole === KanbanBoardMemberRole::KANBAN_ADMIN) {
                $card->setTitle(trim($payload['title']));
            }
        }
        if (array_key_exists('description', $payload)) {
            $card->setDescription($payload['description']);
        }
        if (array_key_exists('priority', $payload)) {
            $card->setPriority($payload['priority'] !== null && $payload['priority'] !== '' ? KanbanCardPriority::tryFrom((string) $payload['priority']) : null);
        }
        if (array_key_exists('dueDate', $payload)) {
            $card->setDueDate($payload['dueDate'] ? new \DateTimeImmutable($payload['dueDate']) : null);
        }
        $allowedColors = ['primary', 'success', 'warning', 'danger', 'info', 'dark'];
        if (array_key_exists('borderColor', $payload)) {
            $color = $payload['borderColor'];
            $card->setBorderColor(
                ($color !== null && $color !== '' && in_array($color, $allowedColors, true)) ? $color : null
            );
        }

        $this->em->flush();

        return $this->json([
            'id' => $card->getId(),
            'title' => $card->getTitle(),
            'description' => $card->getDescription(),
            'priority' => $card->getPriority()?->value,
            'priorityLabel' => $card->getPriority()?->getLabel(),
            'priorityColor' => $card->getPriority()?->getColor(),
            'dueDate' => $card->getDueDate()?->format('c'),
            'borderColor' => $card->getBorderColor(),
            'updatedAt' => $card->getUpdatedAt()?->format('c'),
        ]);
    }

    #[Route('/{id}/assignees', name: 'spa_api_cards_assignees', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function setAssignees(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $card = $this->cardRepo->find($id);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_EDITOR);

        $board = $card->getColumn()->getBoard();
        $project = $board->getProject();
        if (!$project) {
            return $this->json(['error' => 'У доски нет проекта.'], Response::HTTP_BAD_REQUEST);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $userIds = array_slice(array_map('intval', array_filter($payload['user_ids'] ?? [], 'is_numeric')), 0, 1);

        $assigneesToSet = $this->userRepo->findByIds($userIds);
        if ($userIds !== [] && count($assigneesToSet) !== count($userIds)) {
            return $this->json(['error' => 'Пользователь не найден.'], Response::HTTP_NOT_FOUND);
        }

        foreach ($assigneesToSet as $assignee) {
            if ($project->getOwner() !== $assignee && $this->projectUserRepo->findByProjectAndUser($project, $assignee) === null) {
                return $this->json(['error' => 'Пользователь не является участником проекта'], Response::HTTP_BAD_REQUEST);
            }
        }

        $previousAssigneeIds = array_map(static fn (User $u) => $u->getId(), $card->getAssignees()->toArray());
        foreach ($card->getAssignees() as $existing) {
            $card->removeAssignee($existing);
        }

        $newAssignees = [];
        foreach ($assigneesToSet as $assignee) {
            $card->addAssignee($assignee);
            if (!in_array($assignee->getId(), $previousAssigneeIds, true)) {
                $newAssignees[] = $assignee;
            }
        }

        $this->em->flush();

        $boardLink = $this->generateUrl('app_kanban_board', ['id' => $board->getId()]);
        foreach ($newAssignees as $assignee) {
            if ($assignee->getId() !== $user->getId()) {
                $this->notificationService->notifyKanbanTaskAssigned($assignee, $card->getTitle(), $boardLink, false);
            }
        }

        $assignees = [];
        foreach ($card->getAssignees() as $u) {
            $assignees[] = [
                'id' => $u->getId(),
                'name' => trim($u->getLastname() . ' ' . $u->getFirstname()) ?: (string) $u->getId(),
                'firstname' => $u->getFirstname(),
                'lastname' => $u->getLastname(),
            ];
        }

        return $this->json(['assignees' => $assignees]);
    }

    #[Route('/{id}/move', name: 'spa_api_cards_move', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function move(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $card = $this->cardRepo->find($id);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_EDITOR);

        $payload = json_decode($request->getContent(), true) ?? [];
        $columnId = $payload['column_id'] ?? null;
        $position = $payload['position'] ?? null;

        if (!$columnId || $position === null) {
            return $this->json(['error' => 'column_id и position обязательны.'], Response::HTTP_BAD_REQUEST);
        }

        $targetColumn = $this->columnRepo->find($columnId);
        if (!$targetColumn) {
            return $this->json(['error' => 'Колонка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $prevUpdatedAt = isset($payload['prev_updated_at'])
            ? new \DateTimeImmutable($payload['prev_updated_at'])
            : null;

        $oldColumn = $card->getColumn();
        $columnChanged = $oldColumn->getId() !== (int) $columnId;
        $oldColumnTitle = $oldColumn->getTitle();
        $taskTitle = $card->getTitle();

        $this->kanbanService->moveCard($card, $targetColumn, (float) $position, $prevUpdatedAt);

        if ($columnChanged) {
            $board = $targetColumn->getBoard();
            $project = $board->getProject();
            $recipientsById = [];
            foreach ($this->projectUserRepo->findAdminUsersByProject($project) as $u) {
                $recipientsById[$u->getId()] = $u;
            }
            foreach ($card->getAssignees() as $u) {
                $recipientsById[$u->getId()] = $u;
            }
            foreach ($card->getSubtasks() as $subtask) {
                $subtaskUser = $subtask->getUser();
                if ($subtaskUser !== null) {
                    $recipientsById[$subtaskUser->getId()] = $subtaskUser;
                }
            }
            unset($recipientsById[$user->getId()]);

            if ($recipientsById !== []) {
                $authorName = trim($user->getLastname() . ' ' . $user->getFirstname()) ?: $user->getLogin() ?? (string) $user->getId();
                $boardLink = $this->generateUrl('app_kanban_board', ['id' => $board->getId()]);
                $this->notificationService->notifyTaskMovedToRecipients(
                    array_values($recipientsById),
                    $taskTitle,
                    $authorName,
                    $oldColumnTitle,
                    $targetColumn->getTitle(),
                    $boardLink,
                );
            }
        }

        return $this->json([
            'id' => $card->getId(),
            'columnId' => $targetColumn->getId(),
            'position' => $card->getPosition(),
            'updatedAt' => $card->getUpdatedAt()?->format('c'),
        ]);
    }

    #[Route('/{id}', name: 'spa_api_cards_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function deleteCard(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $card = $this->cardRepo->find($id);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_ADMIN);

        $this->em->remove($card);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/archive', name: 'spa_api_cards_archive', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function archive(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $card = $this->cardRepo->find($id);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_ADMIN);

        $card->setIsArchived(!$card->isArchived());
        $this->em->flush();

        return $this->json([
            'id' => $card->getId(),
            'isArchived' => $card->isArchived(),
        ]);
    }
}
