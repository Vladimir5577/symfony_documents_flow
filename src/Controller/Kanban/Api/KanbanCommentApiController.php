<?php

namespace App\Controller\Kanban\Api;

use App\Entity\Kanban\KanbanCardComment;
use App\Entity\User\User;
use App\Enum\Kanban\KanbanBoardMemberRole;
use App\Repository\Kanban\KanbanCardCommentRepository;
use App\Repository\Kanban\KanbanCardRepository;
use App\Repository\Kanban\Project\KanbanProjectUserRepository;
use App\Service\Kanban\KanbanService;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/kanban/cards/{cardId}/comments')]
final class KanbanCommentApiController extends AbstractController
{
    public function __construct(
        private readonly KanbanCardRepository $cardRepo,
        private readonly KanbanCardCommentRepository $commentRepo,
        private readonly KanbanService $kanbanService,
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notificationService,
        private readonly KanbanProjectUserRepository $projectUserRepo,
    ) {
    }

    #[Route('', name: 'api_kanban_comments_list', methods: ['GET'])]
    public function list(int $cardId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($cardId);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_VIEWER);

        $comments = $this->commentRepo->findByCard($card);

        $data = array_map(fn(KanbanCardComment $c) => [
            'id' => $c->getId(),
            'body' => $c->getBody(),
            'authorName' => $c->getAuthor()->getLastname() . ' ' . $c->getAuthor()->getFirstname(),
            'authorId' => $c->getAuthor()->getId(),
            'createdAt' => $c->getCreatedAt()?->format('c'),
        ], $comments);

        return $this->json($data);
    }

    #[Route('', name: 'api_kanban_comments_create', methods: ['POST'])]
    public function create(int $cardId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($cardId);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_EDITOR);

        $payload = json_decode($request->getContent(), true) ?? [];
        $body = trim($payload['body'] ?? '');
        if ($body === '') {
            return $this->json(['error' => 'body обязателен.'], Response::HTTP_BAD_REQUEST);
        }

        $comment = new KanbanCardComment();
        $comment->setBody($body);
        $comment->setCard($card);
        $comment->setAuthor($user);

        $this->em->persist($comment);
        $this->em->flush();

        $this->sendCommentNotifications($card, $user);

        return $this->json([
            'id' => $comment->getId(),
            'body' => $comment->getBody(),
            'authorName' => $user->getLastname() . ' ' . $user->getFirstname(),
            'authorId' => $user->getId(),
            'createdAt' => $comment->getCreatedAt()?->format('c'),
        ], Response::HTTP_CREATED);
    }

    private function sendCommentNotifications(\App\Entity\Kanban\KanbanCard $card, User $commentAuthor): void
    {
        $board = $card->getColumn()->getBoard();
        $project = $board->getProject();
        if (!$project) {
            return;
        }

        $recipients = [];

        foreach ($this->projectUserRepo->findAdminUsersByProject($project) as $u) {
            $recipients[$u->getId()] = $u;
        }
        foreach ($card->getAssignees() as $u) {
            $recipients[$u->getId()] = $u;
        }
        foreach ($card->getSubtasks() as $subtask) {
            $subtaskUser = $subtask->getUser();
            if ($subtaskUser) {
                $recipients[$subtaskUser->getId()] = $subtaskUser;
            }
        }

        unset($recipients[$commentAuthor->getId()]);

        $authorName = trim($commentAuthor->getLastname() . ' ' . $commentAuthor->getFirstname()) ?: $commentAuthor->getLogin();
        $taskTitle = $card->getTitle();
        $link = $this->generateUrl('app_kanban_board', ['id' => $board->getId()]) . '?card=' . $card->getId();

        foreach ($recipients as $recipient) {
            $this->notificationService->notifyTaskCommentAdded($recipient, $authorName, $taskTitle, $link);
        }
    }
}
