<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Entity\Purchase\PurchaseApprovalStep;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseCapability;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseStepPurpose;

/**
 * Права на заявку — одно место на весь модуль.
 *
 * Раньше те же условия были скопированы в контроллеры и продублированы флагами
 * в presenter: несколько мест, которые надо было править синхронно, и любое
 * расхождение давало либо дыру в правах, либо 403 на кнопке, которая
 * нарисовалась. Теперь и гейт, и кнопка спрашивают один метод отсюда.
 *
 * Три источника права, и они не смешиваются:
 *   шаг маршрута  — кто подписывает и кто ведёт ресёрч (адресность шага),
 *   полномочие    — что можно делать вне маршрута (PurchaseCapability),
 *   авторство     — своя заявка, роль для этого не нужна.
 *
 * Видимость и право действовать — разные вещи: видеть заявку может носитель
 * VIEW_ALL, автор и любой участник маршрута, а подписать — только адресат шага,
 * и только когда на этом шаге стоит указатель.
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
     * Человек есть в маршруте хотя бы на одном шаге — лично или через роль.
     * Согласантом может быть кто угодно, включая людей без ролей модуля.
     *
     * Участник видит заявку с момента появления своего шага, не дожидаясь своей
     * очереди: профильный зам становится ответственным сразу, как директор его
     * отметил, и до подписи ему нужно видеть, что происходит.
     */
    public function isRouteParticipant(PurchaseRequest $purchase, User $user): bool
    {
        foreach ($purchase->getSteps() as $step) {
            if ($this->canActOn($step, $user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Шаг адресован этому человеку: лично или через роль модуля.
     *
     * Очерёдность здесь не проверяется: адресат шага и указатель маршрута —
     * разные вещи, вторым занимается PurchaseRequestService.
     */
    public function canActOn(PurchaseApprovalStep $step, User $user): bool
    {
        if ($step->isAddressedTo($user)) {
            return true;
        }

        $code = $step->getRoleCode();

        return $code !== null && $this->roster->hasRole($user, $code);
    }

    /**
     * Шаг, на котором заявка стоит прямо сейчас и который адресован мне; с
     * $purpose — только если это шаг такого назначения.
     *
     * Проверка статуса обязательна: у возвращённой автору заявки шаги позади
     * отказа так и остались PENDING, и указатель формально стоит на них.
     */
    public function findMyActiveStep(
        PurchaseRequest $purchase,
        User $user,
        ?PurchaseStepPurpose $purpose = null,
    ): ?PurchaseApprovalStep {
        if ($purchase->getStatus() !== PurchaseStatus::ON_APPROVAL) {
            return null;
        }

        foreach ($purchase->getActiveSteps() as $step) {
            if ($purpose !== null && $step->getPurpose() !== $purpose) {
                continue;
            }
            if ($this->canActOn($step, $user)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Отметить профильных замов: слот под них в маршруте есть, и разбор, на
     * котором я стою, идёт раньше него.
     *
     * Зеркало проверки в PurchaseRequestService::directorSend, и она остаётся
     * главной — здесь то же условие для кнопки. Считает его сервер, потому что
     * слот живёт в заготовке маршрута из админки: маршрут без слота фронт иначе
     * от маршрута со слотом не отличит и нарисует список, отметки в котором
     * сервер отклонит.
     */
    public function canAssignApprovers(PurchaseRequest $purchase, User $user): bool
    {
        $slot = $purchase->getApproversPosition();
        if ($slot === null) {
            return false;
        }

        $step = $this->findMyActiveStep($purchase, $user, PurchaseStepPurpose::TRIAGE);

        return $step !== null && $step->getPosition() < $slot;
    }

    /**
     * Человека можно отметить профильным замом: роль ему выдана в админке.
     *
     * Единственное место модуля, которое спрашивает конкретную роль, и обойтись
     * без этого нельзя: слот замов заполняется людьми поимённо, а «кем именно
     * можно» — это и есть состав пула. Полномочием такое не выразить, полномочия
     * отвечают за действия вне маршрута.
     *
     * ROLE_ADMIN здесь поблажки не получает, в отличие от полномочий: попасть в
     * подписанты, не будучи замом, значит подписать неизвестно за кого.
     */
    public function canBeProfileDeputy(User $user): bool
    {
        return $this->roster->hasRole($user, PurchaseRoleCode::PROFILE_DEPUTY);
    }

    /**
     * Классификация: категория, закон, способ закупки.
     *
     * Два законных источника права, и оба про закупки: тот, у кого сейчас шаг
     * ресёрча (он и уточняет классификацию по ходу), и тот, кто ведёт
     * справочники модуля — иначе заявку нельзя классифицировать, пока она стоит
     * у директора на разборе, а именно там категория и нужна раньше всего.
     */
    public function canClassify(PurchaseRequest $purchase, User $user): bool
    {
        if ($purchase->getStatus() !== PurchaseStatus::ON_APPROVAL) {
            return false;
        }

        return $this->roster->can($user, PurchaseCapability::MANAGE_DICTIONARIES)
            || $this->findMyActiveStep($purchase, $user, PurchaseStepPurpose::SOURCING) !== null;
    }

    /**
     * Отмена. Автор забирает заявку, пока её не начали исполнять; надзор — до
     * финала; конвейер — на своём отрезке, где уже потрачены деньги.
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

    /**
     * Шаг конвейера исполнения. Платит и закрывает носитель RUN_EXECUTION;
     * доставку подтверждает ещё и автор — товар приходит к нему.
     */
    public function canAdvanceTo(PurchaseRequest $purchase, User $user, ?PurchaseStatus $target): bool
    {
        if ($target === null || $purchase->getStatus()->nextExecutionStatus() !== $target) {
            return false;
        }
        if ($this->roster->can($user, PurchaseCapability::RUN_EXECUTION)) {
            return true;
        }

        return $target === PurchaseStatus::DELIVERED && $this->isOwner($purchase, $user);
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
