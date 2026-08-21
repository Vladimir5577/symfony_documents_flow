<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseHistoryAction;
use App\Enum\Purchase\PurchaseStagePurpose;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Правки содержимого заявки, которая уже идёт по маршруту.
 *
 * Отдельно от движка маршрута: тот отвечает на вопрос «где заявка и кто её
 * закрывает», а здесь меняется то, что согласуют, — состав, количества,
 * поставщик и цены. Разделение не косметическое: править содержимое можно только
 * на определённых этапах, и правило это про этап, а не про статус.
 */
final class PurchaseRequestEditor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PurchaseHistoryLogger $history,
    ) {
    }

    /**
     * Правки состава от разбирающего: снятые галочки и изменённое количество.
     *
     * Позиции не удаляются — заявленный автором состав остаётся в строке, а
     * решение разбирающего ложится рядом (excluded, approvedQuantity).
     * Снять всё нельзя: заявка без единой позиции — это отказ, и оформлять его
     * надо отказом, иначе в закупки уедет пустой заказ.
     *
     * Ни истории, ни flush: правки состава — часть разбора, и записываются они
     * вместе с решением. Закрытая на полпути вкладка иначе оставила бы заявку с
     * урезанным составом, но без решения — у согласанта оказалось бы не то, что
     * разбирающий согласовал.
     *
     * @param array<int, array{included: bool, quantity: string|null}> $itemEdits ключ — id позиции
     * @return list<string> человекочитаемый дифф для истории
     * @throws PurchaseTransitionException
     */
    public function applyItemEdits(PurchaseRequest $request, array $itemEdits): array
    {
        $changes = [];

        foreach ($request->getItems() as $item) {
            $edit = $itemEdits[(int) $item->getId()] ?? null;
            if ($edit === null) {
                continue;
            }

            if ($edit['included'] === false && !$item->isExcluded()) {
                $item->setExcluded(true);
                $changes[] = sprintf('снята позиция «%s»', (string) $item->getName());
                continue;
            }

            $item->setExcluded(false);

            $quantity = $edit['quantity'];
            if ($quantity === null || (float) $quantity === (float) $item->getQuantity()) {
                $item->setApprovedQuantity(null);
                continue;
            }
            if ((float) $quantity <= 0) {
                throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_ITEM);
            }

            $item->setApprovedQuantity($quantity);
            $changes[] = sprintf(
                'количество «%s»: %s → %s',
                (string) $item->getName(),
                rtrim(rtrim((string) $item->getQuantity(), '0'), '.'),
                rtrim(rtrim($quantity, '0'), '.'),
            );
        }

        $hasIncluded = false;
        foreach ($request->getItems() as $item) {
            if (!$item->isExcluded()) {
                $hasIncluded = true;
                break;
            }
        }
        if (!$hasIncluded) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_ITEMS_REQUIRED);
        }

        return $changes;
    }

    /**
     * Результат ресёрча отдела закупок: поставщик и реальные цены.
     *
     * Правится только пока заявка стоит на этапе ресёрча. Дальше нельзя:
     * согласанты, директор и финдиректор подписывают именно эти цифры, и менять их
     * после подписи значит подсунуть согласующим не то, что они видели.
     *
     * @param array<int, string> $priceEdits ключ — id позиции, значение — цена
     * @throws PurchaseTransitionException
     */
    public function applySourcing(
        PurchaseRequest $request,
        User $actor,
        ?string $supplier,
        array $priceEdits,
    ): void {
        $stage = $request->getCurrentStage();
        if ($stage === null || $stage->getPurpose() !== PurchaseStagePurpose::SOURCING) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_TASK_NOT_ACTIVE);
        }

        $changes = [];

        if ($supplier !== null && $supplier !== $request->getSupplier()) {
            $request->setSupplier($supplier !== '' ? $supplier : null);
            $changes[] = $supplier !== ''
                ? sprintf('поставщик: %s', $supplier)
                : 'поставщик снят';
        }

        foreach ($request->getItems() as $item) {
            $price = $priceEdits[(int) $item->getId()] ?? null;
            if ($price === null) {
                continue;
            }
            if (!is_numeric($price) || (float) $price < 0) {
                throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_ITEM);
            }
            if ((float) $price === (float) $item->getEstimatedPrice()) {
                continue;
            }

            $changes[] = sprintf(
                'цена «%s»: %s → %s',
                (string) $item->getName(),
                (string) $item->getEstimatedPrice(),
                $price,
            );
            $item->setEstimatedPrice(number_format((float) $price, 2, '.', ''));
        }

        if ($changes === []) {
            return;
        }

        $this->history->log(
            $request,
            $actor,
            PurchaseHistoryAction::SOURCING_UPDATED,
            implode('; ', $changes),
        );
        $request->touch();
        $this->em->flush();
    }

    /** Запись в историю о чужом действии — файлы и классификация правятся вне этого сервиса. */
    public function log(
        PurchaseRequest $request,
        User $actor,
        PurchaseHistoryAction $action,
        ?string $comment = null,
    ): void {
        $this->history->log($request, $actor, $action, $comment);
        $this->em->flush();
    }
}
