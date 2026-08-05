<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\Inventory\Item;
use App\Entity\Inventory\ItemHistory;
use App\Entity\User\User;
use App\Enum\Inventory\ItemHistoryAction;
use App\Enum\Inventory\ItemStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Пишет журнал движений товара.
 *
 * Записи только persist, без flush: изменение товара и запись истории должны
 * уехать одной транзакцией, поэтому flush делает вызывающий код. Если про него
 * забыли — не сохранится ничего, и это заметно сразу, в отличие от истории,
 * уехавшей без самого изменения.
 */
final class InventoryHistoryLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function logCreated(Item $item): ItemHistory
    {
        return $this->create($item, ItemHistoryAction::CREATED);
    }

    /**
     * Назначение и снятие — одно событие с двумя сторонами.
     */
    public function logAssignment(Item $item, ?User $oldAssignee, ?User $newAssignee): ItemHistory
    {
        $history = $this->create(
            $item,
            $newAssignee === null ? ItemHistoryAction::UNASSIGNED : ItemHistoryAction::ASSIGNED,
        );

        $history->setOldAssignedTo($oldAssignee);
        $history->setNewAssignedTo($newAssignee);

        return $history;
    }

    public function logStatusChanged(Item $item, ItemStatus $oldStatus, ItemStatus $newStatus): ItemHistory
    {
        $history = $this->create($item, ItemHistoryAction::STATUS_CHANGED);
        $history->setOldStatus($oldStatus);
        $history->setNewStatus($newStatus);

        return $history;
    }

    public function logMoved(Item $item, string $oldOrganization, string $newOrganization): ItemHistory
    {
        return $this->create(
            $item,
            ItemHistoryAction::MOVED,
            sprintf('%s → %s', $oldOrganization, $newOrganization),
        );
    }

    public function logCategoryChanged(Item $item, string $oldCategory, string $newCategory): ItemHistory
    {
        return $this->create(
            $item,
            ItemHistoryAction::CATEGORY_CHANGED,
            sprintf('%s → %s', $oldCategory, $newCategory),
        );
    }

    /**
     * Привязка и отвязка документа — одно событие с текстом «было → стало»,
     * как у категории: типизировать ссылку на УПД в истории незачем, её читают глазами.
     */
    public function logUpdChanged(Item $item, string $oldUpd, string $newUpd): ItemHistory
    {
        return $this->create(
            $item,
            ItemHistoryAction::UPD_CHANGED,
            sprintf('%s → %s', $oldUpd, $newUpd),
        );
    }

    private function create(Item $item, ItemHistoryAction $action, ?string $comment = null): ItemHistory
    {
        $user = $this->security->getUser();

        $history = new ItemHistory();
        $history->setItem($item);
        $history->setChangedBy($user instanceof User ? $user : null);
        $history->setAction($action);
        $history->setComment($comment !== null ? mb_substr($comment, 0, 255) : null);

        $this->em->persist($history);

        return $history;
    }
}
