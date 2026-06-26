<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Kanban;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Kanban\KanbanCard;
use App\Entity\Kanban\KanbanCardComment;
use App\Entity\User\User;
use App\Enum\Kanban\KanbanBoardMemberRole;
use App\Repository\Kanban\KanbanCardCommentRepository;
use App\Repository\Kanban\KanbanCardRepository;
use App\Repository\Kanban\Project\KanbanProjectUserRepository;
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

#[Route('/spa/api/cards/{cardId}/comments')]
final class CommentController extends AbstractController
{
    private const MAX_COMMENT_BODY_LENGTH = 10000;
    private const MAX_COMMENTS_PER_CARD = 300;

    public function __construct(
        private readonly KanbanCardRepository $cardRepo,
        private readonly KanbanCardCommentRepository $commentRepo,
        private readonly KanbanService $kanbanService,
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notificationService,
        private readonly KanbanProjectUserRepository $projectUserRepo,
        private readonly KanbanRealtimePublisher $realtimePublisher,
    ) {
    }

    #[Route('', name: 'spa_api_cards_comments_list', requirements: ['cardId' => '\d+'], methods: ['GET'])]
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
            fn (KanbanCardComment $comment): array => $this->formatComment($comment),
            $this->commentRepo->findByCard($card),
        );

        return $this->json($data);
    }

    #[Route('', name: 'spa_api_cards_comments_create', requirements: ['cardId' => '\d+'], methods: ['POST'])]
    public function create(int $cardId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $card = $this->cardRepo->find($cardId);
        if ($card === null) {
            return $this->json(['error' => SpaApiError::CARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_VIEWER);

        $payload = json_decode($request->getContent(), true) ?? [];
        $body = trim((string) ($payload['body'] ?? ''));
        if ($body === '') {
            return $this->json(['error' => SpaApiError::COMMENT_BODY_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        if (mb_strlen($body) > self::MAX_COMMENT_BODY_LENGTH) {
            return $this->json(['error' => SpaApiError::COMMENT_BODY_TOO_LONG], Response::HTTP_BAD_REQUEST);
        }

        if ($this->commentRepo->countByCard($card) >= self::MAX_COMMENTS_PER_CARD) {
            return $this->json(['error' => SpaApiError::COMMENT_LIMIT_REACHED], Response::HTTP_CONFLICT);
        }

        $comment = new KanbanCardComment();
        $comment->setBody($body);
        $comment->setCard($card);
        $comment->setAuthor($user);

        $this->em->persist($comment);
        $this->em->flush();

        $this->sendCommentNotifications($card, $user);

        // Realtime доски: обновляем счётчик комментариев на карточке списка.
        $this->realtimePublisher->publishCardPatch(
            $card,
            $this->realtimePublisher->buildCommentsCount($card),
            $user->getId(),
        );

        return $this->json($this->formatComment($comment), Response::HTTP_CREATED);
    }

    #[Route('/{commentId}', name: 'spa_api_cards_comments_update', requirements: ['cardId' => '\d+', 'commentId' => '\d+'], methods: ['PUT'])]
    public function update(int $cardId, int $commentId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $card = $this->cardRepo->find($cardId);
        if ($card === null) {
            return $this->json(['error' => SpaApiError::CARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_VIEWER);

        $comment = $this->commentRepo->find($commentId);
        if ($comment === null || $comment->getCard()->getId() !== $cardId) {
            return $this->json(['error' => SpaApiError::COMMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if ($comment->getAuthor()->getId() !== $user->getId()) {
            return $this->json(['error' => SpaApiError::COMMENT_AUTHOR_ONLY], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $body = trim((string) ($payload['body'] ?? ''));
        if ($body === '') {
            return $this->json(['error' => SpaApiError::COMMENT_BODY_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        if (mb_strlen($body) > self::MAX_COMMENT_BODY_LENGTH) {
            return $this->json(['error' => SpaApiError::COMMENT_BODY_TOO_LONG], Response::HTTP_BAD_REQUEST);
        }

        $comment->setBody($body);
        $this->em->flush();

        return $this->json($this->formatComment($comment));
    }

    #[Route('/{commentId}', name: 'spa_api_cards_comments_delete', requirements: ['cardId' => '\d+', 'commentId' => '\d+'], methods: ['DELETE'])]
    public function delete(int $cardId, int $commentId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $card = $this->cardRepo->find($cardId);
        if ($card === null) {
            return $this->json(['error' => SpaApiError::CARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_VIEWER);

        $comment = $this->commentRepo->find($commentId);
        if ($comment === null || $comment->getCard()->getId() !== $cardId) {
            return $this->json(['error' => SpaApiError::COMMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if ($comment->getAuthor()->getId() !== $user->getId()) {
            return $this->json(['error' => SpaApiError::COMMENT_AUTHOR_ONLY], Response::HTTP_FORBIDDEN);
        }

        // Снимаем комментарий с карточки, чтобы счётчик ниже отражал состояние
        // после удаления; orphanRemoval удалит запись из БД.
        $card->getComments()->removeElement($comment);
        $this->em->remove($comment);
        $this->em->flush();

        // Realtime доски: обновляем счётчик комментариев на карточке списка.
        $this->realtimePublisher->publishCardPatch(
            $card,
            $this->realtimePublisher->buildCommentsCount($card),
            $user->getId(),
        );

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array{
     *     id: int|null,
     *     body: string,
     *     authorName: string,
     *     authorId: int|null,
     *     createdAt: string|null,
     *     updatedAt: string|null
     * }
     */
    private function formatComment(KanbanCardComment $comment): array
    {
        $author = $comment->getAuthor();

        return [
            'id' => $comment->getId(),
            'body' => $comment->getBody(),
            'authorName' => trim($author->getLastname() . ' ' . $author->getFirstname()),
            'authorId' => $author->getId(),
            'createdAt' => $comment->getCreatedAt()?->format('c'),
            'updatedAt' => $comment->getUpdatedAt()?->format('c'),
        ];
    }

    private function sendCommentNotifications(KanbanCard $card, User $commentAuthor): void
    {
        $board = $card->getColumn()->getBoard();
        $project = $board->getProject();
        if ($project === null) {
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
            if ($subtaskUser !== null) {
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
