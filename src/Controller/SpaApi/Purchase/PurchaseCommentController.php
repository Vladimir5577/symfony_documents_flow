<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestComment;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\User\UserRole;
use App\Repository\Purchase\PurchaseRequestCommentRepository;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Service\Purchase\PurchaseAccess;
use App\Service\Purchase\PurchaseApiPresenter;
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
    private const DEFAULT_PAGE_SIZE = 10;
    private const MAX_PAGE_SIZE = 50;

    public function __construct(
        private readonly PurchaseRequestRepository $purchaseRepo,
        private readonly PurchaseRequestCommentRepository $commentRepo,
        private readonly PurchaseApiPresenter $presenter,
        private readonly EntityManagerInterface $em,
        private readonly PurchaseNotificationPublisher $notifier,
        private readonly PurchaseAccess $access,
    ) {
    }

    #[Route('', name: 'spa_api_purchases_comments_list', methods: ['GET'])]
    public function list(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        if (!$this->canView($purchase, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, $request->query->getInt('page_size', self::DEFAULT_PAGE_SIZE)));
        $result = $this->commentRepo->findPageByPurchase($id, $page, $pageSize);

        return $this->json([
            'items' => array_map(
                $this->presenter->presentComment(...),
                $result['items'],
            ),
            'pagination' => [
                'current_page' => $page,
                'items_per_page' => $pageSize,
                'total_items' => $result['total'],
                'total_pages' => (int) ceil($result['total'] / $pageSize),
            ],
        ]);
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

        // Комментировать может любой, кто видит заявку
        if (!$this->canView($purchase, $user)) {
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

        return $this->json($this->presenter->presentComment($comment), Response::HTTP_CREATED);
    }

    #[Route('/{commentId}', name: 'spa_api_purchases_comments_delete', requirements: ['commentId' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, int $commentId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isGranted(UserRole::ROLE_ADMIN->value)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $comment = null;
        foreach ($purchase->getComments() as $row) {
            if ($row->getId() === $commentId) {
                $comment = $row;
                break;
            }
        }
        if ($comment === null) {
            return $this->json(['error' => SpaApiError::COMMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($comment);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /** Автор заявки — роль не требуется: создавать может любой пользователь. */
    private function isManagerOwner(PurchaseRequest $purchase, User $user): bool
    {
        return $purchase->getCreatedBy()?->getId() === $user->getId();
    }

    /** Видимость заявки — общая на весь модуль, см. PurchaseAccess. */
    private function canView(PurchaseRequest $purchase, User $user): bool
    {
        return $this->access->canView($purchase, $user);
    }
}
