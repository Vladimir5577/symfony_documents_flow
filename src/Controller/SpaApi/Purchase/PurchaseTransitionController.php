<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\Purchase\PurchasePriority;
use App\Enum\Purchase\PurchaseStatus;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Security\Voter\PurchaseRequestVoter;
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

    #[Route('/submit', name: 'spa_api_purchases_submit', methods: ['POST'])]
    public function submit(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->transition($id, $user, PurchaseRequestVoter::SUBMIT,
            fn (PurchaseRequest $purchase, User $actor) => $this->purchaseService->submit($purchase, $actor));
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

        return $this->transition($id, $user, PurchaseRequestVoter::APPROVE,
            fn (PurchaseRequest $purchase, User $actor) => $this->purchaseService->approve($purchase, $actor, $priority));
    }

    /** Возврат на доработку — комментарий обязателен. */
    #[Route('/reject', name: 'spa_api_purchases_reject', methods: ['POST'])]
    public function reject(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $comment = trim((string) ($payload['comment'] ?? ''));
        if ($comment === '') {
            return $this->json(['error' => SpaApiError::PURCHASE_COMMENT_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        return $this->transition($id, $user, PurchaseRequestVoter::REJECT,
            fn (PurchaseRequest $purchase, User $actor) => $this->purchaseService->reject($purchase, $actor, $comment));
    }

    /** Взять в работу: APPROVED → IN_PROGRESS, executor = текущий пользователь. */
    #[Route('/take', name: 'spa_api_purchases_take', methods: ['POST'])]
    public function take(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->transition($id, $user, PurchaseRequestVoter::TAKE,
            fn (PurchaseRequest $purchase, User $actor) => $this->purchaseService->take($purchase, $actor));
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

        return $this->transition($id, $user, PurchaseRequestVoter::ADVANCE,
            fn (PurchaseRequest $purchase, User $actor) => $this->purchaseService->advance($purchase, $actor, $target));
    }

    /** Приёмка департаментом: DELIVERED → DONE. */
    #[Route('/confirm', name: 'spa_api_purchases_confirm', methods: ['POST'])]
    public function confirm(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->transition($id, $user, PurchaseRequestVoter::CONFIRM,
            fn (PurchaseRequest $purchase, User $actor) => $this->purchaseService->confirm($purchase, $actor));
    }

    #[Route('/cancel', name: 'spa_api_purchases_cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $comment = trim((string) ($payload['comment'] ?? ''));

        return $this->transition($id, $user, PurchaseRequestVoter::CANCEL,
            fn (PurchaseRequest $purchase, User $actor) => $this->purchaseService->cancel($purchase, $actor, $comment !== '' ? $comment : null));
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

        return $this->transition($id, $user, PurchaseRequestVoter::SET_PRIORITY,
            fn (PurchaseRequest $purchase, User $actor) => $this->purchaseService->setPriority($purchase, $actor, $priority));
    }

    /**
     * @param callable(PurchaseRequest, User): void $action
     */
    private function transition(int $id, ?User $user, string $attribute, callable $action): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isGranted($attribute, $purchase)) {
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
