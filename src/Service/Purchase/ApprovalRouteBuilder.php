<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Entity\Purchase\PurchaseApprovalStep;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseApproverKind;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Enum\User\UserRole;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Маршрут согласования заявки. Он фиксирован и зависит только от кнопки создания:
 *
 *   быстрая  → отдел закупок
 *   обычная  → директор → согласанты (кого отметил директор) → отдел закупок
 *
 * Настраиваемых шаблонов и порогов по сумме больше нет: сумма на маршрут не влияет,
 * «быстрая» — это форма заявки, и она же решает, идти ли к директору.
 *
 * Согласанты появляются не при подаче, а в момент решения директора — до него
 * неизвестно, кто они и есть ли вообще. Поэтому под них зарезервирована позиция
 * между директором и отделом закупок: вставка в середину не двигает соседей.
 */
final class ApprovalRouteBuilder
{
    private const POSITION_DIRECTOR = 1;

    /** Позиция согласантов. Все они параллельны: заявка идёт дальше, когда согласовали все. */
    public const POSITION_APPROVERS = 2;

    private const POSITION_DEPARTMENT = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Построить маршрут заявки. Существующие шаги сносятся целиком: повторная
     * подача после доработки начинает согласование с нуля.
     * Flush — на вызывающей стороне.
     */
    public function build(PurchaseRequest $request): void
    {
        foreach ($request->getSteps()->toArray() as $step) {
            $request->removeStep($step);
            $this->em->remove($step);
        }

        if ($request->getCreatedAs() === PurchaseRequestKind::FAST) {
            $this->addRoleStep($request, self::POSITION_DIRECTOR, UserRole::ROLE_PURCHASE_DEPARTMENT, 'Отдел закупок');

            return;
        }

        $this->addRoleStep($request, self::POSITION_DIRECTOR, UserRole::ROLE_PURCHASE_DIRECTOR, 'Согласование директора');
        $this->addRoleStep($request, self::POSITION_DEPARTMENT, UserRole::ROLE_PURCHASE_DEPARTMENT, 'Отдел закупок');
    }

    /**
     * Повесить на заявку согласантов, отмеченных директором.
     *
     * Автор в список не попадает: сам себе согласантом человек не бывает, а
     * заявка на нём бы и застряла. Flush — на вызывающей стороне.
     *
     * @param list<User> $users
     */
    public function addApprovers(PurchaseRequest $request, array $users): void
    {
        $authorId = $request->getCreatedBy()?->getId();
        $seen = [];

        foreach ($users as $user) {
            $userId = $user->getId();
            if ($userId === null || $userId === $authorId || isset($seen[$userId])) {
                continue;
            }
            $seen[$userId] = true;

            $step = (new PurchaseApprovalStep())
                ->setPosition(self::POSITION_APPROVERS)
                ->setApproverKind(PurchaseApproverKind::USER)
                ->setApproverUser($user);

            $request->addStep($step);
            $this->em->persist($step);
        }
    }

    /**
     * Превью маршрута для формы создания: что получится при выбранной кнопке.
     * Считает бэк, чтобы фронт не дублировал правила.
     *
     * @return list<array{position: int, title: string}>
     */
    public function preview(PurchaseRequestKind $kind): array
    {
        if ($kind === PurchaseRequestKind::FAST) {
            return [['position' => self::POSITION_DIRECTOR, 'title' => 'Отдел закупок']];
        }

        return [
            ['position' => self::POSITION_DIRECTOR, 'title' => 'Согласование директора'],
            ['position' => self::POSITION_APPROVERS, 'title' => 'Согласанты (назначает директор)'],
            ['position' => self::POSITION_DEPARTMENT, 'title' => 'Отдел закупок'],
        ];
    }

    private function addRoleStep(PurchaseRequest $request, int $position, UserRole $role, string $title): void
    {
        $step = (new PurchaseApprovalStep())
            ->setPosition($position)
            ->setApproverKind(PurchaseApproverKind::ROLE)
            ->setApproverRole($role->value)
            ->setTitle($title);

        $request->addStep($step);
        $this->em->persist($step);
    }
}
