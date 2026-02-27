<?php

namespace App\Controller\Kanban\Api;

use App\Entity\User\User;
use App\Enum\KanbanBoardMemberRole;
use App\Enum\KanbanCardPriority;
use App\Repository\Kanban\KanbanCardRepository;
use App\Repository\Kanban\KanbanColumnRepository;
use App\Repository\User\UserRepository;
use App\Service\Kanban\KanbanService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/kanban/cards')]
final class KanbanCardApiController extends AbstractController
{
    public function __construct(
        private readonly KanbanCardRepository $cardRepo,
        private readonly KanbanColumnRepository $columnRepo,
        private readonly KanbanService $kanbanService,
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepo,
    ) {
    }

    #[Route('', name: 'api_kanban_cards_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

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

        $this->kanbanService->requireRole($column->getBoard(), $user, KanbanBoardMemberRole::EDITOR);

        $card = $this->kanbanService->createCard($column, $title);

        return $this->json([
            'id' => $card->getId(),
            'title' => $card->getTitle(),
            'position' => $card->getPosition(),
            'columnId' => $column->getId(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_kanban_cards_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->findOneWithRelations($id);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::VIEWER);

        $checklist = [];
        foreach ($card->getChecklistItems() as $ci) {
            $checklist[] = [
                'id' => $ci->getId(),
                'title' => $ci->getTitle(),
                'isCompleted' => $ci->isCompleted(),
                'position' => $ci->getPosition(),
            ];
        }

        $comments = [];
        foreach ($card->getComments() as $com) {
            $comments[] = [
                'id' => $com->getId(),
                'body' => $com->getBody(),
                'authorName' => $com->getAuthor()->getFirstname() . ' ' . $com->getAuthor()->getLastname(),
                'createdAt' => $com->getCreatedAt()?->format('c'),
            ];
        }

        $attachments = [];
        foreach ($card->getAttachments() as $att) {
            $attachments[] = [
                'id' => $att->getId(),
                'filename' => $att->getFilename(),
                'contentType' => $att->getContentType(),
                'sizeBytes' => $att->getSizeBytes(),
                'createdAt' => $att->getCreatedAt()?->format('c'),
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
            $assignees[] = ['id' => $u->getId(), 'name' => $u->getFirstname() . ' ' . $u->getLastname()];
        }

        return $this->json([
            'id' => $card->getId(),
            'title' => $card->getTitle(),
            'description' => $card->getDescription(),
            'position' => $card->getPosition(),
            'priority' => $card->getPriority()?->value,
            'priorityLabel' => $card->getPriority()?->getLabel(),
            'priorityColor' => $card->getPriority()?->getColor(),
            'dueAt' => $card->getDueAt()?->format('c'),
            'isArchived' => $card->isArchived(),
            'columnId' => $card->getColumn()->getId(),
            'columnTitle' => $card->getColumn()->getTitle(),
            'boardId' => $card->getColumn()->getBoard()->getId(),
            'checklist' => $checklist,
            'comments' => $comments,
            'attachments' => $attachments,
            'labels' => $labels,
            'assignees' => $assignees,
            'borderColor' => $card->getBorderColor(),
            'createdAt' => $card->getCreatedAt()?->format('c'),
            'updatedAt' => $card->getUpdatedAt()?->format('c'),
        ]);
    }

    #[Route('/{id}', name: 'api_kanban_cards_update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($id);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::EDITOR);

        $payload = json_decode($request->getContent(), true) ?? [];

        if (isset($payload['title']) && trim($payload['title']) !== '') {
            $card->setTitle(trim($payload['title']));
        }
        if (array_key_exists('description', $payload)) {
            $card->setDescription($payload['description']);
        }
        if (array_key_exists('priority', $payload)) {
            $card->setPriority($payload['priority'] !== null ? KanbanCardPriority::tryFrom((int) $payload['priority']) : null);
        }
        if (array_key_exists('dueAt', $payload)) {
            $card->setDueAt($payload['dueAt'] ? new \DateTimeImmutable($payload['dueAt']) : null);
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
            'dueAt' => $card->getDueAt()?->format('c'),
            'borderColor' => $card->getBorderColor(),
            'updatedAt' => $card->getUpdatedAt()?->format('c'),
        ]);
    }

    #[Route('/{id}/assignees', name: 'api_kanban_cards_assignees', methods: ['PUT'])]
    public function setAssignees(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($id);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::EDITOR);

        $payload = json_decode($request->getContent(), true) ?? [];
        $userIds = array_slice(array_map('intval', array_filter($payload['user_ids'] ?? [], 'is_numeric')), 0, 1);

        foreach ($card->getAssignees() as $existing) {
            $card->removeAssignee($existing);
        }

        foreach ($this->userRepo->findByIds($userIds) as $assignee) {
            $card->addAssignee($assignee);
        }

        $this->em->flush();

        $assignees = [];
        foreach ($card->getAssignees() as $u) {
            $assignees[] = ['id' => $u->getId(), 'name' => $u->getFirstname() . ' ' . $u->getLastname()];
        }

        return $this->json(['assignees' => $assignees]);
    }

    #[Route('/{id}/move', name: 'api_kanban_cards_move', methods: ['POST'])]
    public function move(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($id);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::EDITOR);

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

        $this->kanbanService->moveCard($card, $targetColumn, (float) $position, $prevUpdatedAt);

        return $this->json([
            'id' => $card->getId(),
            'columnId' => $targetColumn->getId(),
            'position' => $card->getPosition(),
            'updatedAt' => $card->getUpdatedAt()?->format('c'),
        ]);
    }

    #[Route('/{id}', name: 'api_kanban_cards_delete', methods: ['DELETE'])]
    public function deleteCard(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($id);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::EDITOR);

        $this->em->remove($card);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/archive', name: 'api_kanban_cards_archive', methods: ['PATCH'])]
    public function archive(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($id);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::EDITOR);

        $card->setIsArchived(!$card->isArchived());
        $this->em->flush();

        return $this->json([
            'id' => $card->getId(),
            'isArchived' => $card->isArchived(),
        ]);
    }
}
