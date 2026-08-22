<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseApprovalStage;
use App\Entity\Purchase\PurchaseApprovalTask;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseCapability;
use App\Enum\Purchase\PurchasePriority;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;
use App\Repository\User\UserRepository;
use App\Service\Purchase\PurchaseAccess;
use App\Service\Purchase\PurchaseApiPresenter;
use App\Service\Purchase\PurchaseApprovalWorkflow;
use App\Service\Purchase\PurchaseTransitionException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Движение заявки: подача и решения по задачам маршрута.
 *
 * Право решить не выводится из статуса и зашитого списка ролей — оно читается из
 * самой задачи: кому она адресована, тот и решает. Поэтому ни новый согласующий,
 * ни перенос оплаты на другого человека не требуют правок здесь.
 *
 * Отдельных ручек под конвейер исполнения нет: оплата, поставка и закрытие — те
 * же задачи маршрута, и закрываются той же кнопкой, что подпись бухгалтерии.
 */
#[Route('/spa/api/purchases/{id}', requirements: ['id' => '\d+'])]
final class PurchaseTransitionController extends AbstractController
{
    public function __construct(
        private readonly PurchaseRequestRepository $purchaseRepo,
        private readonly PurchaseRouteTemplateRepository $templateRepo,
        private readonly UserRepository $userRepo,
        private readonly PurchaseApprovalWorkflow $workflow,
        private readonly PurchaseApiPresenter $presenter,
        private readonly PurchaseAccess $access,
    ) {
    }

    /** Подать заявку: автор, из редактируемого статуса. Маршрут соберётся сам. */
    #[Route('/submit', name: 'spa_api_purchases_submit', methods: ['POST'])]
    public function submit(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->transition($id, $user,
            fn (PurchaseRequest $p, User $u) => $this->access->isOwner($p, $u),
            fn (PurchaseRequest $p, User $u) => $this->workflow->submit($p, $u));
    }

    /** Закрыть свою задачу согласием. */
    #[Route('/tasks/{taskId}/approve', name: 'spa_api_purchases_task_approve', requirements: ['taskId' => '\d+'], methods: ['POST'])]
    public function approveTask(int $id, int $taskId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $comment = $this->comment($request);

        return $this->taskAction($id, $taskId, $user,
            fn (PurchaseRequest $p, PurchaseApprovalTask $t, User $u) => $this->workflow
                ->approveTask($p, $t, $u, $comment !== '' ? $comment : null));
    }

    /** Вернуть автору со своей задачи. Комментарий обязателен. */
    #[Route('/tasks/{taskId}/reject', name: 'spa_api_purchases_task_reject', requirements: ['taskId' => '\d+'], methods: ['POST'])]
    public function rejectTask(int $id, int $taskId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $comment = $this->comment($request);
        if ($comment === '') {
            return $this->json(['error' => SpaApiError::PURCHASE_COMMENT_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        return $this->taskAction($id, $taskId, $user,
            fn (PurchaseRequest $p, PurchaseApprovalTask $t, User $u) => $this->workflow
                ->rejectTask($p, $t, $u, $comment));
    }

    /**
     * Вернуть в отдел закупок со своей задачи — для бухгалтерии, юристов и всех,
     * кто идёт после закупок: они бракуют документы, а не саму заявку.
     * Комментарий обязателен.
     */
    #[Route('/tasks/{taskId}/return', name: 'spa_api_purchases_task_return', requirements: ['taskId' => '\d+'], methods: ['POST'])]
    public function returnTask(int $id, int $taskId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $comment = $this->comment($request);
        if ($comment === '') {
            return $this->json(['error' => SpaApiError::PURCHASE_COMMENT_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        return $this->taskAction($id, $taskId, $user,
            fn (PurchaseRequest $p, PurchaseApprovalTask $t, User $u) => $this->workflow
                ->returnToSourcing($p, $t, $u, $comment));
    }

    /**
     * Снять свою подпись — своё решение и только его. Чью именно подпись можно
     * снять, проверяет воркфлоу, поэтому ролевого гейта здесь нет: он спрашивал
     * «ты директор» и расходился со строкой списка, где кнопка отката
     * показывалась любому подписавшему. Чужую подпись снимает возврат маршрута.
     */
    #[Route('/tasks/{taskId}/revoke', name: 'spa_api_purchases_task_revoke', requirements: ['taskId' => '\d+'], methods: ['POST'])]
    public function revokeTask(int $id, int $taskId, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->taskAction($id, $taskId, $user,
            fn (PurchaseRequest $p, PurchaseApprovalTask $t, User $u) => $this->workflow
                ->revokeTask($p, $t, $u));
    }

    /**
     * Сменить маршрут заявки, пока она на разборе.
     *
     * Заявку это не двигает: разбирающий остаётся на разборе нового маршрута и
     * отправляет её дальше обычной кнопкой.
     */
    #[Route('/route', name: 'spa_api_purchases_route_change', methods: ['PATCH'])]
    public function changeRoute(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        // Гейта «ты директор» нет: право даёт сама задача разбора, адресованная
        // этому человеку. Кто разбирает заявки, решает маршрут, а не контроллер.
        $task = $this->access->findMyActiveTask($purchase, $user, PurchaseStagePurpose::TRIAGE);
        if ($task === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_ROUTE_NOT_CHANGEABLE], Response::HTTP_CONFLICT);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $template = $this->templateRepo->findWithStages((int) ($payload['templateId'] ?? 0));
        if ($template === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_ROUTE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->workflow->changeRoute($purchase, $task, $template, $user);
        } catch (PurchaseTransitionException $e) {
            return $this->json(['error' => $e->errorCode], Response::HTTP_CONFLICT);
        }

        return $this->json($this->presenter->presentDetail($purchase));
    }

    /**
     * Решение разбирающего — одно на все кнопки модалки разбора.
     *
     * body: {action, items?: [{id, included, quantity}], assignments?: {stageId: [userId]},
     *        approverIds?: [], reason?}
     *   send   — применить правки состава и отправить дальше: выбранным
     *            согласантам, а если никого не выбрали — сразу следующему этапу
     *   reject — мотивированный отказ: автору на доработку, причина обязательна
     *   cancel — отказ без объяснений: заявка отменена
     */
    #[Route('/triage', name: 'spa_api_purchases_triage', methods: ['POST'])]
    public function triage(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $task = $this->access->findMyActiveTask($purchase, $user, PurchaseStagePurpose::TRIAGE);
        if ($task === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_TASK_NOT_ACTIVE], Response::HTTP_CONFLICT);
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
                    $assignments = $this->collectAssignments($purchase, $payload);
                    if (is_string($assignments)) {
                        return $this->json(['error' => $assignments], Response::HTTP_BAD_REQUEST);
                    }
                    $this->workflow->triage(
                        $purchase,
                        $task,
                        $user,
                        $this->collectItemEdits($payload['items'] ?? []),
                        $assignments,
                    );
                    break;

                case 'reject':
                    if ($reason === '') {
                        return $this->json(['error' => SpaApiError::PURCHASE_COMMENT_REQUIRED], Response::HTTP_BAD_REQUEST);
                    }
                    $this->workflow->rejectTask($purchase, $task, $user, $reason);
                    break;

                case 'cancel':
                    $this->workflow->cancel($purchase, $user, $reason !== '' ? $reason : null);
                    break;

                default:
                    return $this->json(['error' => SpaApiError::PURCHASE_INVALID_STATUS], Response::HTTP_BAD_REQUEST);
            }
        } catch (PurchaseTransitionException $e) {
            return $this->json(['error' => $e->errorCode], Response::HTTP_CONFLICT);
        }

        return $this->json($this->presenter->presentDetail($purchase));
    }

    /** Отмена: автор — до исполнения; допущенный к деньгам — на исполнении; надзор — всегда. */
    #[Route('/cancel', name: 'spa_api_purchases_cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $comment = $this->comment($request);

        return $this->transition($id, $user,
            fn (PurchaseRequest $p, User $u) => $this->access->canCancel($p, $u),
            fn (PurchaseRequest $p, User $u) => $this->workflow->cancel($p, $u, $comment !== '' ? $comment : null));
    }

    /** Смена приоритета — полномочие надзора. */
    #[Route('/priority', name: 'spa_api_purchases_priority', methods: ['POST'])]
    public function priority(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $priority = PurchasePriority::tryFrom((string) ($payload['priority'] ?? ''));
        if ($priority === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_INVALID_PRIORITY], Response::HTTP_BAD_REQUEST);
        }

        return $this->transition($id, $user,
            fn (PurchaseRequest $p, User $u) => $this->access->can($u, PurchaseCapability::SUPERVISE),
            fn (PurchaseRequest $p, User $u) => $this->workflow->setPriority($p, $u, $priority));
    }

    /**
     * Правки состава из payload. Позиции, которых разбирающий не прислал,
     * остаются как есть.
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
     * Согласанты по этапам: {stageId: [userId]}.
     *
     * Плоский approverIds тоже принимаем — маршрут с одним динамическим этапом
     * самый частый, и заставлять фронт узнавать его id ради одного списка незачем.
     *
     * Несуществующий пользователь — ошибка, а не молчаливый пропуск: разбирающий
     * считает, что отправил человеку, а тот заявки не увидит. Так же и человек не
     * из пула этапа: назначение решает, кто будет подписывать заявку, и проверять
     * это только скрытием в списке нельзя.
     *
     * @param array<string, mixed> $payload
     * @return array<int, list<User>>|string код ошибки, если состав не годится
     */
    private function collectAssignments(PurchaseRequest $purchase, array $payload): array|string
    {
        $rows = $payload['assignments'] ?? null;

        if (!is_array($rows)) {
            $flat = $payload['approverIds'] ?? [];
            if (!is_array($flat) || $flat === []) {
                return [];
            }

            $stages = $this->access->findAssignableStages($purchase, $this->currentUser());
            if (count($stages) !== 1) {
                return SpaApiError::PURCHASE_TASK_NOT_ACTIVE;
            }

            $rows = [(string) $stages[0]->getId() => $flat];
        }

        $assignments = [];
        foreach ($rows as $stageId => $ids) {
            if (!is_array($ids)) {
                return SpaApiError::PURCHASE_TASK_NOT_FOUND;
            }

            $stage = $this->findStage($purchase, (int) $stageId);
            if ($stage === null) {
                return SpaApiError::PURCHASE_TASK_NOT_FOUND;
            }

            $users = [];
            foreach ($ids as $userId) {
                $candidate = $this->userRepo->find((int) $userId);
                if ($candidate === null) {
                    return SpaApiError::USER_NOT_FOUND;
                }
                if (!$this->access->canBeAssignedTo($stage, $candidate)) {
                    return SpaApiError::PURCHASE_APPROVER_NOT_DEPUTY;
                }
                $users[] = $candidate;
            }

            $assignments[(int) $stageId] = $users;
        }

        return $assignments;
    }

    private function findStage(PurchaseRequest $purchase, int $stageId): ?PurchaseApprovalStage
    {
        foreach ($purchase->getStages() as $stage) {
            if ($stage->getId() === $stageId) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Решение по задаче: задача должна принадлежать заявке и быть адресована
     * этому человеку — лично или через роль, записанную на ней.
     *
     * @param callable(PurchaseRequest, PurchaseApprovalTask, User): void $action
     */
    private function taskAction(int $id, int $taskId, ?User $user, callable $action): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $task = $purchase->findTask($taskId);
        if ($task === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_TASK_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->access->canActOn($task, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        try {
            $action($purchase, $task, $user);
        } catch (PurchaseTransitionException $e) {
            return $this->json(['error' => $e->errorCode], Response::HTTP_CONFLICT);
        }

        return $this->json($this->presenter->presentDetail($purchase));
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

    private function comment(Request $request): string
    {
        $payload = json_decode($request->getContent(), true) ?? [];

        return is_array($payload) ? trim((string) ($payload['comment'] ?? '')) : '';
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
