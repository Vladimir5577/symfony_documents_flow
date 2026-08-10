<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\User\UserRole;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Видимость заявки — одна функция на весь модуль.
 *
 * Раньше тот же набор условий был скопирован в PurchaseController,
 * PurchaseCommentController и PurchaseFileController: три места, которые надо
 * было править синхронно, и любое расхождение давало либо дыру в правах, либо
 * 403 на кнопке, которая нарисовалась.
 *
 * Видимость и право согласовать — разные вещи: видеть заявку может директор,
 * финдиректор, отдел закупок, автор и любой участник маршрута, а согласовать —
 * только тот, на чьём шаге стоит указатель (это решает PurchaseRequestService).
 */
final class PurchaseAccess
{
    public function __construct(
        private readonly Security $security,
    ) {}

    public function canView(PurchaseRequest $purchase, User $user): bool
    {
        $status = $purchase->getStatus();

        // Директор и финдиректор видят всё, кроме чужих черновиков
        if (($this->security->isGranted(UserRole::ROLE_PURCHASE_DIRECTOR->value)
                || $this->security->isGranted(UserRole::ROLE_PURCHASE_FINANCE_DIRECTOR->value))
            && in_array($status, PurchaseStatus::getDirectorVisible(), true)
        ) {
            return true;
        }
        if ($this->security->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value)
            && in_array($status, PurchaseStatus::getPurchaseDepartmentVisible(), true)
        ) {
            return true;
        }
        if ($this->security->isGranted(UserRole::ROLE_PURCHASE_INVOICE->value)
            && in_array($status, PurchaseStatus::getPayerVisible(), true)
        ) {
            return true;
        }
        if ($purchase->getCreatedBy()?->getId() === $user->getId()) {
            return true;
        }

        return $this->isRouteParticipant($purchase, $user);
    }

    /**
     * Человек есть в маршруте хотя бы на одном шаге — лично или через роль.
     * Согласантом может быть кто угодно, включая людей без закупочных ролей.
     */
    public function isRouteParticipant(PurchaseRequest $purchase, User $user): bool
    {
        foreach ($purchase->getSteps() as $step) {
            if ($step->isAddressedTo($user)) {
                return true;
            }
            $role = $step->getApproverRole();
            if ($role !== null && $this->security->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
