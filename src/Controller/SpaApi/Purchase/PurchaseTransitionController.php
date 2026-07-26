<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\Purchase\PurchasePriority;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\User\UserRole;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Service\Purchase\PurchaseApiPresenter;
use App\Service\Purchase\PurchaseRequestService;
use App\Service\Purchase\PurchaseTransitionException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Переходы статусов заявки. Каждый переход — отдельный POST,
 * в ответ — обновлённая карточка заявки.
 *
 * Доступ проверяется ролью (+ владением для действий автора);
 * корректность самого перехода из текущего статуса стережёт
 * PurchaseRequestService (кидает 409).
 */
#[Route('/spa/api/purchases/{id}', requirements: ['id' => '\d+'])]
final class PurchaseTransitionController extends AbstractController
{
    public function __construct(
        private readonly PurchaseRequestRepository $purchaseRepo,
        private readonly PurchaseRequestService $purchaseService,
        private readonly PurchaseApiPresenter $presenter,
    ) {
    }

    /** Подать заявку: автор, из редактируемого статуса (проверит сервис). */
    #[Route('/submit', name: 'spa_api_purchases_submit', methods: ['POST'])]
    public function submit(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->transition($id, $user,
            fn (PurchaseRequest $p, User $u) => $this->isManagerOwner($p, $u),
            fn (PurchaseRequest $p, User $u) => $this->purchaseService->submit($p, $u));
    }

    /** Отдел закупок направляет рассмотренную заявку директору. */
    #[Route('/send-to-director', name: 'spa_api_purchases_send_to_director', methods: ['POST'])]
    public function sendToDirector(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->transition($id, $user,
            fn (PurchaseRequest $p) => $this->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value)
                && $p->getStatus() === PurchaseStatus::PENDING_REVIEW,
            fn (PurchaseRequest $p, User $u) => $this->purchaseService->sendToDirector($p, $u));
    }

    #[Route('/approve', name: 'spa_api_purchases_approve', methods: ['POST'])]
    public function approve(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $priorityRaw = $payload['priority'] ?? null;

        $priority = null;
        if ($priorityRaw !== null && $priorityRaw !== '') {
            $priority = PurchasePriority::tryFrom((string) $priorityRaw);
            if ($priority === null) {
                return $this->json(['error' => SpaApiError::PURCHASE_INVALID_PRIORITY], Response::HTTP_BAD_REQUEST);
            }
        }

        return $this->transition($id, $user,
            fn () => $this->isGranted(UserRole::ROLE_PURCHASE_DIRECTOR->value),
            fn (PurchaseRequest $p, User $u) => $this->purchaseService->approve($p, $u, $priority));
    }

    /**
     * Возврат на доработку — комментарий обязателен.
     * Отдел закупок — с рассмотрения; директор — с согласования и из APPROVED до взятия в работу.
     */
    #[Route('/reject', name: 'spa_api_purchases_reject', methods: ['POST'])]
    public function reject(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $comment = trim((string) ($payload['comment'] ?? ''));
        if ($comment === '') {
            return $this->json(['error' => SpaApiError::PURCHASE_COMMENT_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        return $this->transition($id, $user,
            fn (PurchaseRequest $p) => (
                $this->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value) && $p->getStatus() === PurchaseStatus::PENDING_REVIEW
            ) || (
                $this->isGranted(UserRole::ROLE_PURCHASE_DIRECTOR->value)
                && in_array($p->getStatus(), [PurchaseStatus::PENDING_APPROVAL, PurchaseStatus::APPROVED], true)
            ),
            fn (PurchaseRequest $p, User $u) => $this->purchaseService->reject($p, $u, $comment));
    }

    /** Взять в работу: APPROVED → IN_PROGRESS, executor = текущий пользователь. */
    #[Route('/take', name: 'spa_api_purchases_take', methods: ['POST'])]
    public function take(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->transition($id, $user,
            fn () => $this->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value),
            fn (PurchaseRequest $p, User $u) => $this->purchaseService->take($p, $u));
    }

    /** Шаг конвейера исполнения: body.status должен быть строго следующим. */
    #[Route('/status', name: 'spa_api_purchases_status', methods: ['POST'])]
    public function status(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $target = PurchaseStatus::tryFrom((string) ($payload['status'] ?? ''));
        if ($target === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_INVALID_STATUS], Response::HTTP_BAD_REQUEST);
        }

        return $this->transition($id, $user,
            fn () => $this->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value),
            fn (PurchaseRequest $p, User $u) => $this->purchaseService->advance($p, $u, $target));
    }

    /** Приёмка автором: DELIVERED → DONE. */
    #[Route('/confirm', name: 'spa_api_purchases_confirm', methods: ['POST'])]
    public function confirm(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->transition($id, $user,
            fn (PurchaseRequest $p, User $u) => $this->isManagerOwner($p, $u),
            fn (PurchaseRequest $p, User $u) => $this->purchaseService->confirm($p, $u));
    }

    /** Отмена: автор — до взятия в работу; отдел закупок — на исполнении; директор — всегда. */
    #[Route('/cancel', name: 'spa_api_purchases_cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $comment = trim((string) ($payload['comment'] ?? ''));

        return $this->transition($id, $user,
            fn (PurchaseRequest $p, User $u) => $this->canCancel($p, $u),
            fn (PurchaseRequest $p, User $u) => $this->purchaseService->cancel($p, $u, $comment !== '' ? $comment : null));
    }

    /** Смена приоритета (директор). */
    #[Route('/priority', name: 'spa_api_purchases_priority', methods: ['POST'])]
    public function priority(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $priority = PurchasePriority::tryFrom((string) ($payload['priority'] ?? ''));
        if ($priority === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_INVALID_PRIORITY], Response::HTTP_BAD_REQUEST);
        }

        return $this->transition($id, $user,
            fn () => $this->isGranted(UserRole::ROLE_PURCHASE_DIRECTOR->value),
            fn (PurchaseRequest $p, User $u) => $this->purchaseService->setPriority($p, $u, $priority));
    }

    private function isManagerOwner(PurchaseRequest $purchase, User $user): bool
    {
        return $this->isGranted(UserRole::ROLE_MANAGER->value)
            && $purchase->getCreatedBy()?->getId() === $user->getId();
    }

    private function canCancel(PurchaseRequest $purchase, User $user): bool
    {
        if ($purchase->getStatus()->isFinal()) {
            return false;
        }
        if ($this->isGranted(UserRole::ROLE_PURCHASE_DIRECTOR->value)) {
            return true;
        }
        if ($this->isManagerOwner($purchase, $user)) {
            return in_array($purchase->getStatus(), [
                PurchaseStatus::DRAFT, PurchaseStatus::PENDING_REVIEW,
                PurchaseStatus::PENDING_APPROVAL, PurchaseStatus::APPROVED, PurchaseStatus::REJECTED,
            ], true);
        }
        if ($this->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value)) {
            return in_array($purchase->getStatus(), [
                PurchaseStatus::IN_PROGRESS, PurchaseStatus::AWAITING_PAYMENT,
                PurchaseStatus::PAID, PurchaseStatus::DELIVERED,
            ], true);
        }

        return false;
    }

    /**
     * @param callable(PurchaseRequest, User): bool $gate
     * @param callable(PurchaseRequest, User): void $action
     */
    private function transition(int $id, ?User $user, callable $gate, callable $action): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$gate($purchase, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        try {
            $action($purchase, $user);
        } catch (PurchaseTransitionException $e) {
            return $this->json(['error' => $e->errorCode], Response::HTTP_CONFLICT);
        }

        return $this->json($this->presenter->presentDetail($purchase));
    }
}
