<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\User\UserRole;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Repository\User\UserRepository;
use App\Service\Purchase\PurchaseApiPresenter;
use App\Service\Purchase\PurchaseRequestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Приглашённые согласанты заявки: отдел закупок приглашает/убирает
 * на этапе рассмотрения, приглашённый подтверждает тогглом.
 */
#[Route('/spa/api/purchases/{id}/approvers', requirements: ['id' => '\d+'])]
final class PurchaseApproverController extends AbstractController
{
    public function __construct(
        private readonly PurchaseRequestRepository $purchaseRepo,
        private readonly UserRepository $userRepo,
        private readonly PurchaseRequestService $purchaseService,
        private readonly PurchaseApiPresenter $presenter,
    ) {
    }

    #[Route('', name: 'spa_api_purchases_approvers_invite', methods: ['POST'])]
    public function invite(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->requirePurchase($id);
        if ($purchase instanceof JsonResponse) {
            return $purchase;
        }

        if (!$this->canManageApprovers($purchase)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $invited = $this->userRepo->find((int) ($payload['userId'] ?? 0));
        if ($invited === null) {
            return $this->json(['error' => SpaApiError::USER_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->purchaseService->inviteApprover($purchase, $user, $invited);

        return $this->json($this->presenter->presentDetail($purchase), Response::HTTP_CREATED);
    }

    #[Route('/{userId}', name: 'spa_api_purchases_approvers_remove', requirements: ['userId' => '\d+'], methods: ['DELETE'])]
    public function remove(int $id, int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        $purchase = $this->requirePurchase($id);
        if ($purchase instanceof JsonResponse) {
            return $purchase;
        }

        if (!$this->canManageApprovers($purchase)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $target = $this->userRepo->find($userId);
        $approver = $target !== null ? $purchase->findApproverFor($target) : null;
        if ($approver === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_APPROVER_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->purchaseService->removeApprover($purchase, $approver);

        return $this->json($this->presenter->presentDetail($purchase));
    }

    /** Тоггл согласанта: body {confirmed: bool}. Доступно только приглашённому на этапе рассмотрения. */
    #[Route('/confirm', name: 'spa_api_purchases_approvers_confirm', methods: ['POST'])]
    public function confirm(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $purchase = $this->requirePurchase($id);
        if ($purchase instanceof JsonResponse) {
            return $purchase;
        }

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $approver = $purchase->findApproverFor($user);
        if ($approver === null || $purchase->getStatus() !== PurchaseStatus::PENDING_REVIEW) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $confirmed = (bool) ($payload['confirmed'] ?? true);

        $this->purchaseService->confirmApproval($purchase, $approver, $confirmed);

        return $this->json($this->presenter->presentDetail($purchase));
    }

    /** Приглашать/убирать согласантов может отдел закупок на этапе рассмотрения. */
    private function canManageApprovers(PurchaseRequest $purchase): bool
    {
        return $this->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value)
            && $purchase->getStatus() === PurchaseStatus::PENDING_REVIEW;
    }

    private function requirePurchase(int $id): PurchaseRequest|JsonResponse
    {
        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        return $purchase;
    }
}
