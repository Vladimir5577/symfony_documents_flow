<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRequestComment;
use App\Entity\User\User;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Security\Voter\PurchaseRequestVoter;
use App\Service\Purchase\PurchaseNotificationPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/purchases/{id}/comments', requirements: ['id' => '\d+'])]
final class PurchaseCommentController extends AbstractController
{
    private const MAX_COMMENT_LENGTH = 10000;

    public function __construct(
        private readonly PurchaseRequestRepository $purchaseRepo,
        private readonly EntityManagerInterface $em,
        private readonly PurchaseNotificationPublisher $notifier,
    ) {
    }

    #[Route('', name: 'spa_api_purchases_comments_create', methods: ['POST'])]
    public function create(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isGranted(PurchaseRequestVoter::COMMENT, $purchase)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $text = trim((string) ($payload['text'] ?? ''));
        if ($text === '') {
            return $this->json(['error' => SpaApiError::COMMENT_BODY_REQUIRED], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($text) > self::MAX_COMMENT_LENGTH) {
            return $this->json(['error' => SpaApiError::COMMENT_BODY_TOO_LONG], Response::HTTP_BAD_REQUEST);
        }

        $comment = new PurchaseRequestComment();
        $comment->setAuthor($user);
        $comment->setText($text);
        $purchase->addComment($comment);

        $this->em->persist($comment);
        $this->em->flush();

        $this->notifier->notifyCommentAdded($purchase, $user);

        $authorName = trim(($user->getLastname() ?? '') . ' ' . ($user->getFirstname() ?? '')) ?: (string) $user->getLogin();

        return $this->json([
            'id' => $comment->getId(),
            'author' => ['id' => $user->getId(), 'name' => $authorName],
            'text' => $comment->getText(),
            'createdAt' => $comment->getCreatedAt()?->format('c'),
        ], Response::HTTP_CREATED);
    }
}
