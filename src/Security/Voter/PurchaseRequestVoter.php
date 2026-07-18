<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\User\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Права на заявку закупки.
 *
 * Роли: ROLE_MANAGER — создаёт и видит заявки своего узла организации;
 * ROLE_PURCHASE_DIRECTOR — видит все, согласует/возвращает;
 * ROLE_PURCHASE_DEPARTMENT — видит APPROVED+ и двигает исполнение.
 */
final class PurchaseRequestVoter extends Voter
{
    public const VIEW = 'PURCHASE_VIEW';
    public const EDIT = 'PURCHASE_EDIT';
    public const DELETE = 'PURCHASE_DELETE';
    public const SUBMIT = 'PURCHASE_SUBMIT';
    public const APPROVE = 'PURCHASE_APPROVE';
    public const REJECT = 'PURCHASE_REJECT';
    public const TAKE = 'PURCHASE_TAKE';
    public const ADVANCE = 'PURCHASE_ADVANCE';
    public const CONFIRM = 'PURCHASE_CONFIRM';
    public const CANCEL = 'PURCHASE_CANCEL';
    public const SET_PRIORITY = 'PURCHASE_SET_PRIORITY';
    public const COMMENT = 'PURCHASE_COMMENT';

    private const ATTRIBUTES = [
        self::VIEW,
        self::EDIT,
        self::DELETE,
        self::SUBMIT,
        self::APPROVE,
        self::REJECT,
        self::TAKE,
        self::ADVANCE,
        self::CONFIRM,
        self::CANCEL,
        self::SET_PRIORITY,
        self::COMMENT,
    ];

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::ATTRIBUTES, true) && $subject instanceof PurchaseRequest;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof PurchaseRequest) {
            return false;
        }

        $roles = $this->roleHierarchy->getReachableRoleNames($token->getRoleNames());
        $isDirector = in_array(UserRole::ROLE_PURCHASE_DIRECTOR->value, $roles, true);
        $isPurchase = in_array(UserRole::ROLE_PURCHASE_DEPARTMENT->value, $roles, true);
        $isDepartmentManager = in_array(UserRole::ROLE_MANAGER->value, $roles, true)
            && $this->sameOrganization($user, $subject);

        $status = $subject->getStatus();

        return match ($attribute) {
            self::VIEW => $isDirector
                || $isDepartmentManager
                || ($isPurchase && in_array($status, PurchaseStatus::getPurchaseDepartmentVisible(), true)),

            self::EDIT, self::SUBMIT => $isDepartmentManager && $status->isEditable(),

            self::DELETE => $isDepartmentManager && $status === PurchaseStatus::DRAFT,

            self::APPROVE, self::REJECT => $isDirector && $status === PurchaseStatus::PENDING_APPROVAL,

            self::TAKE => $isPurchase && $status === PurchaseStatus::APPROVED,

            self::ADVANCE => $isPurchase && $status->nextExecutionStatus() !== null,

            self::CONFIRM => $isDepartmentManager && $status === PurchaseStatus::DELIVERED,

            // Департамент отменяет только до взятия в работу; дальше — директор или закупки
            self::CANCEL => !$status->isFinal() && (
                $isDirector
                || ($isDepartmentManager && in_array($status, [PurchaseStatus::DRAFT, PurchaseStatus::PENDING_APPROVAL, PurchaseStatus::APPROVED, PurchaseStatus::REJECTED], true))
                || ($isPurchase && in_array($status, [PurchaseStatus::IN_PROGRESS, PurchaseStatus::AWAITING_PAYMENT, PurchaseStatus::PAID, PurchaseStatus::DELIVERED], true))
            ),

            self::SET_PRIORITY => $isDirector && !$status->isFinal(),

            self::COMMENT => $isDirector
                || $isDepartmentManager
                || ($isPurchase && in_array($status, PurchaseStatus::getPurchaseDepartmentVisible(), true)),

            default => false,
        };
    }

    private function sameOrganization(User $user, PurchaseRequest $request): bool
    {
        $userOrg = $user->getOrganization();
        $requestOrg = $request->getOrganization();

        return $userOrg !== null
            && $requestOrg !== null
            && $userOrg->getId() === $requestOrg->getId();
    }
}
