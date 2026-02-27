<?php

namespace App\Controller\Kanban\Api;

use App\Entity\Kanban\KanbanCardComment;
use App\Entity\User\User;
use App\Enum\KanbanBoardMemberRole;
use App\Repository\Kanban\KanbanCardCommentRepository;
use App\Repository\Kanban\KanbanCardRepository;
use App\Service\Kanban\KanbanService;
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
    ) {
    }

    #[Route('', name: 'api_kanban_comments_list', methods: ['GET'])]
    public function list(string $cardId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($cardId);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::VIEWER);

        $comments = $this->commentRepo->findByCard($card);

        $data = array_map(fn(KanbanCardComment $c) => [
            'id' => (string) $c->getId(),
            'body' => $c->getBody(),
            'authorName' => $c->getAuthor()->getFirstname() . ' ' . $c->getAuthor()->getLastname(),
            'authorId' => $c->getAuthor()->getId(),
            'createdAt' => $c->getCreatedAt()?->format('c'),
        ], $comments);

        return $this->json($data);
    }

    #[Route('', name: 'api_kanban_comments_create', methods: ['POST'])]
    public function create(string $cardId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($cardId);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::EDITOR);

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

        return $this->json([
            'id' => (string) $comment->getId(),
            'body' => $comment->getBody(),
            'authorName' => $user->getFirstname() . ' ' . $user->getLastname(),
            'authorId' => $user->getId(),
            'createdAt' => $comment->getCreatedAt()?->format('c'),
        ], Response::HTTP_CREATED);
    }
}
