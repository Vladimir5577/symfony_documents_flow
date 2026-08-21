<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Entity\Purchase\PurchaseApprovalStage;
use App\Entity\Purchase\PurchaseApprovalTask;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseCapability;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseTaskDecision;

/**
 * Права на заявку — одно место на весь модуль.
 *
 * Раньше те же условия были скопированы в контроллеры и продублированы флагами в
 * presenter: несколько мест, которые надо было править синхронно, и любое
 * расхождение давало либо дыру в правах, либо 403 на кнопке, которая
 * нарисовалась. Теперь и гейт, и кнопка спрашивают один метод отсюда.
 *
 * Три источника права, и они не смешиваются:
 *   задача маршрута — кто закрывает этап: подписывает, ведёт ресёрч, платит,
 *                     принимает поставку (адресность задачи),
 *   полномочие      — что можно делать вне маршрута (PurchaseCapability),
 *   авторство       — своя заявка, роль для этого не нужна.
 *
 * Исполнение стало частью маршрута, и полномочия RUN_EXECUTION больше не хватает,
 * чтобы оплатить или закрыть заявку: нужна задача. Полномочие отвечает на вопрос
 * «допущен ли человек к деньгам вообще», задача — «его ли это заявка сейчас».
 * Прежде первое отвечало на оба, и «доставку принимает склад, а не заявитель»
 * нельзя было настроить, не меняя код.
 *
 * Видимость и право действовать — разные вещи: видеть заявку может носитель
 * VIEW_ALL, автор и любой участник маршрута, а закрыть задачу — только её адресат,
 * и только когда на её этапе стоит указатель.
 */
final class PurchaseAccess
{
    public function __construct(
        private readonly PurchaseRoster $roster,
    ) {
    }

    public function canView(PurchaseRequest $purchase, User $user): bool
    {
        // Чужой черновик не видит никто: заявки ещё нет, есть замысел автора.
        if ($this->roster->can($user, PurchaseCapability::VIEW_ALL)
            && $purchase->getStatus() !== PurchaseStatus::DRAFT
        ) {
            return true;
        }
        if ($this->isOwner($purchase, $user)) {
            return true;
        }

        return $this->isRouteParticipant($purchase, $user);
    }

    /**
     * Человек есть в маршруте хотя бы в одной задаче — лично или через роль.
     * Согласантом может быть кто угодно, включая людей без ролей модуля.
     *
     * Участник видит заявку с момента появления своей задачи, не дожидаясь своей
     * очереди: согласант становится ответственным сразу, как его отметили, и до
     * подписи ему нужно видеть, что происходит.
     */
    public function isRouteParticipant(PurchaseRequest $purchase, User $user): bool
    {
        foreach ($purchase->getAllTasks() as $task) {
            if ($this->canActOn($task, $user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Задача адресована этому человеку: лично, через роль модуля или как автору.
     *
     * Очерёдность здесь не проверяется: адресат задачи и указатель маршрута —
     * разные вещи, вторым занимается PurchaseApprovalWorkflow.
     */
    public function canActOn(PurchaseApprovalTask $task, User $user): bool
    {
        if ($task->isAddressedTo($user)) {
            return true;
        }

        $code = $task->getRoleCode();

        return $code !== null && $this->roster->hasRole($user, $code);
    }

    /**
     * Моя задача в этапе, на котором заявка стоит прямо сейчас; с $purpose —
     * только если этап такого назначения.
     *
     * Проверка статуса обязательна: у возвращённой автору заявки задачи позади
     * отказа так и остались PENDING, а этап — активным.
     */
    public function findMyActiveTask(
        PurchaseRequest $purchase,
        User $user,
        ?PurchaseStagePurpose $purpose = null,
    ): ?PurchaseApprovalTask {
        if (!$purchase->getStatus()->isInRoute()) {
            return null;
        }

        $stage = $purchase->getCurrentStage();
        if ($stage === null || ($purpose !== null && $stage->getPurpose() !== $purpose)) {
            return null;
        }

        foreach ($stage->getPendingTasks() as $task) {
            if ($this->canActOn($task, $user)) {
                return $task;
            }
        }

        return null;
    }

    /**
     * Моя подпись, которую ещё можно снять.
     *
     * Только своя и только на согласовании: на исполнении через подпись прошли
     * деньги, и снять её значило бы разойтись с банком.
     *
     * Из нескольких своих подписей — последняя. Отзыв отматывает маршрут к её
     * этапу, и кнопка, снимающая первую подпись, сожгла бы всё, что после неё
     * подписали остальные, — а человек нажимал «я передумал».
     */
    public function findMyRevokableTask(PurchaseRequest $purchase, User $user): ?PurchaseApprovalTask
    {
        if (!in_array($purchase->getStatus(), [PurchaseStatus::ON_APPROVAL, PurchaseStatus::APPROVED], true)) {
            return null;
        }

        $mine = null;
        foreach ($purchase->getStages() as $stage) {
            if ($stage->getPurpose()->isExecution()) {
                continue;
            }
            foreach ($stage->getTasks() as $task) {
                // Именно APPROVED: отказ откатом не снимают, для возврата после
                // отказа есть повторная подача.
                if ($task->getDecision() === PurchaseTaskDecision::APPROVED
                    && $task->getDecidedBy()?->getId() === $user->getId()
                ) {
                    $mine = $task;
                }
            }
        }

        return $mine;
    }

    /**
     * Этапы, на которые я могу выбрать согласантов: динамические, ещё не
     * заполненные, и я стою на разборе этой заявки.
     *
     * Считает сервер, потому что этапы живут в заготовке из админки: маршрут без
     * динамического этапа фронт иначе от маршрута с ним не отличит и нарисует
     * список, отметки в котором сервер отклонит.
     *
     * @return list<PurchaseApprovalStage>
     */
    public function findAssignableStages(PurchaseRequest $purchase, User $user): array
    {
        if ($this->findMyActiveTask($purchase, $user, PurchaseStagePurpose::TRIAGE) === null) {
            return [];
        }

        $stages = [];
        foreach ($purchase->getStages() as $stage) {
            if ($stage->isAwaitingAssignment() && $stage->isDynamic()) {
                $stages[] = $stage;
            }
        }

        return $stages;
    }

    /**
     * Человека можно отметить согласантом на этом этапе: роль пула ему выдана.
     *
     * Единственное место модуля, которое спрашивает конкретную роль, и обойтись
     * без этого нельзя: динамический этап заполняется людьми поимённо, а «кем
     * именно можно» — это и есть состав пула. Полномочием такое не выразить,
     * полномочия отвечают за действия вне маршрута.
     *
     * ROLE_ADMIN здесь поблажки не получает, в отличие от полномочий: попасть в
     * подписанты, не входя в пул, значит подписать неизвестно за кого.
     */
    public function canBeAssignedTo(PurchaseApprovalStage $stage, User $user): bool
    {
        $pool = $stage->getCandidateRoleCode();

        return $pool !== null && $this->roster->hasRole($user, $pool);
    }

    /** Пул профильных замов — для списка кандидатов, когда этап ещё не выбран. */
    public function canBeProfileDeputy(User $user): bool
    {
        return $this->roster->hasRole($user, PurchaseRoleCode::PROFILE_DEPUTY);
    }

    /**
     * Сменить маршрут заявки: я стою на её разборе.
     *
     * Дальше разбора нельзя — в маршруте уже лежат чужие решения, и пересборка
     * сожгла бы их. Зеркало проверки в воркфлоу, и она остаётся главной; здесь то
     * же условие для кнопки.
     */
    public function canChangeRoute(PurchaseRequest $purchase, User $user): bool
    {
        return $this->findMyActiveTask($purchase, $user, PurchaseStagePurpose::TRIAGE) !== null;
    }

    /**
     * Вернуть заявку в отдел закупок со своей задачи: этап ресёрча в маршруте
     * есть и он раньше моего.
     *
     * В маршруте без ресёрча возвращать некуда, и кнопки не будет.
     */
    public function canReturnToSourcing(PurchaseRequest $purchase, User $user): bool
    {
        $task = $this->findMyActiveTask($purchase, $user);
        if ($task === null) {
            return false;
        }

        $sourcing = $purchase->findStageByPurpose(PurchaseStagePurpose::SOURCING);
        $stage = $task->getStage();

        return $sourcing !== null
            && $stage !== null
            && $sourcing->getPosition() < $stage->getPosition();
    }

    /**
     * Классификация: категория, закон, способ закупки.
     *
     * Два законных источника права, и оба про закупки: тот, у кого сейчас этап
     * ресёрча (он и уточняет классификацию по ходу), и тот, кто ведёт справочники
     * модуля — иначе заявку нельзя классифицировать, пока она стоит у директора
     * на разборе, а именно там категория и нужна раньше всего.
     */
    public function canClassify(PurchaseRequest $purchase, User $user): bool
    {
        if ($purchase->getStatus() !== PurchaseStatus::ON_APPROVAL) {
            return false;
        }

        return $this->roster->can($user, PurchaseCapability::MANAGE_DICTIONARIES)
            || $this->findMyActiveTask($purchase, $user, PurchaseStagePurpose::SOURCING) !== null;
    }

    /**
     * Отмена. Автор забирает заявку, пока её не начали исполнять; надзор — до
     * финала; допущенный к деньгам — на отрезке, где они уже потрачены.
     */
    public function canCancel(PurchaseRequest $purchase, User $user): bool
    {
        $status = $purchase->getStatus();
        if ($status->isFinal()) {
            return false;
        }
        if ($this->roster->can($user, PurchaseCapability::SUPERVISE)) {
            return true;
        }
        if ($this->isOwner($purchase, $user)) {
            return in_array($status, [
                PurchaseStatus::DRAFT,
                PurchaseStatus::ON_APPROVAL,
                PurchaseStatus::APPROVED,
                PurchaseStatus::REJECTED,
            ], true);
        }

        return $this->roster->can($user, PurchaseCapability::RUN_EXECUTION)
            && in_array($status, [PurchaseStatus::INVOICE_PAID, PurchaseStatus::DELIVERED], true);
    }

    public function can(User $user, PurchaseCapability $capability): bool
    {
        return $this->roster->can($user, $capability);
    }

    /** Автор заявки — роль не требуется: создавать может любой пользователь. */
    public function isOwner(PurchaseRequest $purchase, User $user): bool
    {
        return $purchase->getCreatedBy()?->getId() === $user->getId();
    }
}
