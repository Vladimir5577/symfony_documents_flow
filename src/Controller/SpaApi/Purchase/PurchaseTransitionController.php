<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseApprovalStep;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\Purchase\PurchasePriority;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\User\UserRole;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Repository\User\UserRepository;
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
 * Движение заявки: подача, решения по шагам маршрута, конвейер исполнения.
 *
 * Право согласовать больше не выводится из статуса и зашитого списка ролей —
 * оно читается из самого шага: кому он адресован, тот и решает. Поэтому
 * добавление нового согласующего не требует правок здесь.
 */
#[Route('/spa/api/purchases/{id}', requirements: ['id' => '\d+'])]
final class PurchaseTransitionController extends AbstractController
{
    public function __construct(
        private readonly PurchaseRequestRepository $purchaseRepo,
        private readonly UserRepository $userRepo,
        private readonly PurchaseRequestService $purchaseService,
        private readonly PurchaseApiPresenter $presenter,
    ) {
    }

    /** Подать заявку: автор, из редактируемого статуса. Маршрут соберётся сам. */
    #[Route('/submit', name: 'spa_api_purchases_submit', methods: ['POST'])]
    public function submit(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->transition($id, $user,
            fn (PurchaseRequest $p, User $u) => $this->isOwner($p, $u),
            fn (PurchaseRequest $p, User $u) => $this->purchaseService->submit($p, $u));
    }

    /** Согласовать свой шаг маршрута. */
    #[Route('/steps/{stepId}/approve', name: 'spa_api_purchases_step_approve', requirements: ['stepId' => '\d+'], methods: ['POST'])]
    public function approveStep(int $id, int $stepId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $comment = trim((string) ($payload['comment'] ?? ''));

        return $this->stepAction($id, $stepId, $user,
            fn (PurchaseRequest $p, PurchaseApprovalStep $s, User $u) => $this->purchaseService
                ->approveStep($p, $s, $u, $comment !== '' ? $comment : null));
    }

    /** Вернуть автору со своего шага. Комментарий обязателен. */
    #[Route('/steps/{stepId}/reject', name: 'spa_api_purchases_step_reject', requirements: ['stepId' => '\d+'], methods: ['POST'])]
    public function rejectStep(int $id, int $stepId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $comment = trim((string) ($payload['comment'] ?? ''));
        if ($comment === '') {
            return $this->json(['error' => SpaApiError::PURCHASE_COMMENT_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        return $this->stepAction($id, $stepId, $user,
            fn (PurchaseRequest $p, PurchaseApprovalStep $s, User $u) => $this->purchaseService
                ->rejectStep($p, $s, $u, $comment));
    }

    /**
     * Снять свою подпись. Только директор и только со своего решения —
     * чужую подпись снимает отзыв маршрута отделом закупок.
     */
    #[Route('/steps/{stepId}/revoke', name: 'spa_api_purchases_step_revoke', requirements: ['stepId' => '\d+'], methods: ['POST'])]
    public function revokeStep(int $id, int $stepId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->isGranted(UserRole::ROLE_PURCHASE_DIRECTOR->value)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        return $this->stepAction($id, $stepId, $user,
            fn (PurchaseRequest $p, PurchaseApprovalStep $s, User $u) => $this->purchaseService
                ->revokeStep($p, $s, $u));
    }

    /**
     * Решение директора из разбора новых заявок — одно на все кнопки модалки.
     *
     * body: {action, items?: [{id, included, quantity}], approverIds?: [], reason?}
     *   send   — применить правки состава и отправить дальше: отмеченным
     *            согласантам, а если никого не отметили — сразу в отдел закупок
     *   reject — мотивированный отказ: автору на доработку, причина обязательна
     *   cancel — отказ без объяснений: заявка отменена
     *   defer  — «рассмотрю позже»: остаётся у директора, уходит в конец очереди
     */
    #[Route('/director-decision', name: 'spa_api_purchases_director_decision', methods: ['POST'])]
    public function directorDecision(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isGranted(UserRole::ROLE_PURCHASE_DIRECTOR->value)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $step = $this->findDirectorStep($purchase);
        if ($step === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_STEP_NOT_ACTIVE], Response::HTTP_CONFLICT);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $action = (string) ($payload['action'] ?? '');
        $reason = trim((string) ($payload['reason'] ?? ''));

        try {
            switch ($action) {
                case 'send':
                    $approvers = $this->collectApprovers($payload['approverIds'] ?? []);
                    if ($approvers === null) {
                        return $this->json(['error' => SpaApiError::USER_NOT_FOUND], Response::HTTP_BAD_REQUEST);
                    }
                    $this->purchaseService->directorSend(
                        $purchase,
                        $step,
                        $user,
                        $this->collectItemEdits($payload['items'] ?? []),
                        $approvers,
                    );
                    break;

                case 'reject':
                    if ($reason === '') {
                        return $this->json(['error' => SpaApiError::PURCHASE_COMMENT_REQUIRED], Response::HTTP_BAD_REQUEST);
                    }
                    $this->purchaseService->rejectStep($purchase, $step, $user, $reason);
                    break;

                case 'cancel':
                    $this->purchaseService->cancel($purchase, $user, $reason !== '' ? $reason : null);
                    break;

                case 'defer':
                    $this->purchaseService->directorDefer($purchase, $step);
                    break;

                default:
                    return $this->json(['error' => SpaApiError::PURCHASE_INVALID_STATUS], Response::HTTP_BAD_REQUEST);
            }
        } catch (PurchaseTransitionException $e) {
            return $this->json(['error' => $e->errorCode], Response::HTTP_CONFLICT);
        }

        return $this->json($this->presenter->presentDetail($purchase));
    }

    /** Незакрытый шаг директора. У обычной заявки он первый, у быстрой его нет вовсе. */
    private function findDirectorStep(PurchaseRequest $purchase): ?PurchaseApprovalStep
    {
        foreach ($purchase->getSteps() as $step) {
            if ($step->getApproverRole() === UserRole::ROLE_PURCHASE_DIRECTOR->value && $step->isPending()) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Правки состава из payload. Позиции, которых директор не прислал, остаются как есть.
     *
     * @param mixed $rows
     * @return array<int, array{included: bool, quantity: string|null}>
     */
    private function collectItemEdits(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $edits = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }

            $quantity = $row['quantity'] ?? null;
            $edits[(int) $row['id']] = [
                'included' => (bool) ($row['included'] ?? true),
                'quantity' => is_numeric($quantity) ? (string) $quantity : null,
            ];
        }

        return $edits;
    }

    /**
     * Согласанты по id. Пустой список — штатно: заявка уйдёт сразу в отдел закупок.
     * Несуществующий id — ошибка, а не молчаливый пропуск: директор считает,
     * что отправил человеку, а тот заявки не увидит.
     *
     * @return list<User>|null null — в списке есть неизвестный id
     */
    private function collectApprovers(mixed $ids): ?array
    {
        if (!is_array($ids)) {
            return [];
        }

        $users = [];
        foreach ($ids as $id) {
            $approver = $this->userRepo->find((int) $id);
            if ($approver === null) {
                return null;
            }
            $users[] = $approver;
        }

        return $users;
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
            // Конвейер ведёт отдел закупок; отметку «Оплачено» может поставить и плательщик.
            fn () => $this->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value)
                || ($this->isGranted(UserRole::ROLE_PURCHASE_INVOICE->value) && $target === PurchaseStatus::INVOICE_PAID),
            fn (PurchaseRequest $p, User $u) => $this->purchaseService->advance($p, $u, $target));
    }

    /** Закрытие DELIVERED → DONE: автор подтверждает получение, отдел закупок может закрыть сам. */
    #[Route('/confirm', name: 'spa_api_purchases_confirm', methods: ['POST'])]
    public function confirm(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->transition($id, $user,
            fn (PurchaseRequest $p, User $u) => $this->isOwner($p, $u)
                || $this->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value),
            fn (PurchaseRequest $p, User $u) => $this->purchaseService->confirm($p, $u));
    }

    /** Отмена: автор — до исполнения; отдел закупок — на исполнении; директор — всегда. */
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

    /**
     * Решение по шагу: шаг должен принадлежать заявке и быть адресован
     * этому человеку — лично или через роль, записанную на шаге.
     *
     * @param callable(PurchaseRequest, PurchaseApprovalStep, User): void $action
     */
    private function stepAction(int $id, int $stepId, ?User $user, callable $action): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $step = null;
        foreach ($purchase->getSteps() as $candidate) {
            if ($candidate->getId() === $stepId) {
                $step = $candidate;
                break;
            }
        }
        if ($step === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_STEP_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $addressed = $step->isAddressedTo($user)
            || ($step->getApproverRole() !== null && $this->isGranted($step->getApproverRole()));
        if (!$addressed) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        try {
            $action($purchase, $step, $user);
        } catch (PurchaseTransitionException $e) {
            return $this->json(['error' => $e->errorCode], Response::HTTP_CONFLICT);
        }

        return $this->json($this->presenter->presentDetail($purchase));
    }

    /** Автор заявки — роль не требуется: создавать может любой пользователь. */
    private function isOwner(PurchaseRequest $purchase, User $user): bool
    {
        return $purchase->getCreatedBy()?->getId() === $user->getId();
    }

    private function canCancel(PurchaseRequest $purchase, User $user): bool
    {
        if ($purchase->getStatus()->isFinal()) {
            return false;
        }
        if ($this->isGranted(UserRole::ROLE_PURCHASE_DIRECTOR->value)) {
            return true;
        }
        if ($this->isOwner($purchase, $user)) {
            return in_array($purchase->getStatus(), [
                PurchaseStatus::DRAFT,
                PurchaseStatus::ON_APPROVAL,
                PurchaseStatus::APPROVED,
                PurchaseStatus::REJECTED,
            ], true);
        }
        if ($this->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value)) {
            return in_array($purchase->getStatus(), [
                PurchaseStatus::INVOICE_SENT,
                PurchaseStatus::INVOICE_PAID,
                PurchaseStatus::DELIVERED,
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
