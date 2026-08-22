<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\Inventory\NomenclatureItem;
use App\Entity\Inventory\NomenclatureHistory;
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

    public function logCreated(NomenclatureItem $item): NomenclatureHistory
    {
        return $this->create($item, ItemHistoryAction::CREATED);
    }

    /**
     * Назначение и снятие — одно событие с двумя сторонами.
     */
    public function logAssignment(NomenclatureItem $item, ?User $oldAssignee, ?User $newAssignee): NomenclatureHistory
    {
        $history = $this->create(
            $item,
            $newAssignee === null ? ItemHistoryAction::UNASSIGNED : ItemHistoryAction::ASSIGNED,
        );

        $history->setOldAssignedTo($oldAssignee);
        $history->setNewAssignedTo($newAssignee);

        return $history;
    }

    public function logStatusChanged(NomenclatureItem $item, ItemStatus $oldStatus, ItemStatus $newStatus): NomenclatureHistory
    {
        $history = $this->create($item, ItemHistoryAction::STATUS_CHANGED);
        $history->setOldStatus($oldStatus);
        $history->setNewStatus($newStatus);

        return $history;
    }

    public function logMoved(NomenclatureItem $item, string $oldOrganization, string $newOrganization): NomenclatureHistory
    {
        return $this->create(
            $item,
            ItemHistoryAction::MOVED,
            sprintf('%s → %s', $oldOrganization, $newOrganization),
        );
    }

    /**
     * Смена вида товара. Категория приезжает вместе с видом и отдельно не логируется:
     * в ленте это одно событие, читаемое глазами как «было → стало».
     */
    public function logNomenclatureChanged(
        NomenclatureItem $item,
        string $oldNomenclature,
        string $newNomenclature,
    ): NomenclatureHistory {
        return $this->create(
            $item,
            ItemHistoryAction::NOMENCLATURE_CHANGED,
            sprintf('%s → %s', $oldNomenclature, $newNomenclature),
        );
    }

    /**
     * Привязка и отвязка документа — одно событие с текстом «было → стало»,
     * как у вида: типизировать ссылку на УПД в истории незачем, её читают глазами.
     */
    public function logUpdChanged(NomenclatureItem $item, string $oldUpd, string $newUpd): NomenclatureHistory
    {
        return $this->create(
            $item,
            ItemHistoryAction::UPD_CHANGED,
            sprintf('%s → %s', $oldUpd, $newUpd),
        );
    }

    private function create(NomenclatureItem $item, ItemHistoryAction $action, ?string $comment = null): NomenclatureHistory
    {
        $user = $this->security->getUser();

        $history = new NomenclatureHistory();
        $history->setItem($item);
        $history->setChangedBy($user instanceof User ? $user : null);
        $history->setAction($action);
        $history->setComment($comment !== null ? mb_substr($comment, 0, 255) : null);

        $this->em->persist($history);

        return $history;
    }
}
