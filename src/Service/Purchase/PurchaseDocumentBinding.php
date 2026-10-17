<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseContract;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\Purchase\PurchaseRequestItem;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseRequestKind;

/**
 * Раскладка позиций заявки по договорам и счетам.
 *
 * Большая закупка идёт по нескольким договорам, а по договору приходит несколько
 * счетов. Поэтому связь лежит на позиции: у каждой свой договор и свой счёт.
 * Счёт отдельно к договору не привязан — эта связь читается через позиции.
 *
 * Счёт и договор на позиции выбирают явно, даже если документ один.
 *
 * Позиция «есть на складе» не покупается: ни счёта, ни договора у неё нет.
 */
final class PurchaseDocumentBinding
{
    /** Цвета счетов по порядку загрузки. Те же предлагает палитра на фронте. */
    public const INVOICE_COLORS = [
        '#2563EB', '#16A34A', '#EA580C', '#9333EA', '#DB2777', '#0891B2', '#CA8A04', '#DC2626',
    ];

    /** Новый файл: счёту — свободный цвет, позициям — единственный документ. */
    public function onFileAdded(PurchaseRequest $request, PurchaseRequestFile $file): void
    {
        if ($file->getType() === PurchaseFileType::INVOICE && $file->getColor() === null) {
            $file->setColor($this->nextInvoiceColor($request, $file));
        }
    }

    /**
     * Файл удаляют: позиции его теряют. Вызывать до удаления строки — внешний
     * ключ обнулит колонку в базе, но не в уже загруженных объектах.
     */
    public function onFileRemoved(PurchaseRequest $request, PurchaseRequestFile $file): void
    {
        foreach ($request->getItems() as $item) {
            if ($item->getInvoiceFile() === $file) {
                $item->setInvoiceFile(null);
            }
        }
    }

    /**
     * Правки раскладки из модалки. Присланное поле меняется, остальное остаётся.
     *
     * @param list<array<string, mixed>> $itemRows {id, contractId?, invoiceFileId?, inStock?}
     * @param list<array<string, mixed>> $fileRows {id, color}
     * @throws PurchaseTransitionException
     */
    public function apply(PurchaseRequest $request, array $itemRows, array $fileRows): void
    {
        foreach ($fileRows as $row) {
            $file = is_int($row['id'] ?? null) ? $this->findFile($request, $row['id'], PurchaseFileType::INVOICE) : null;
            $color = strtoupper((string) ($row['color'] ?? ''));
            if ($file === null || preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) {
                throw new PurchaseTransitionException(SpaApiError::PURCHASE_BINDING_INVALID);
            }
            $file->setColor($color);
        }

        foreach ($itemRows as $row) {
            $item = is_int($row['id'] ?? null) ? $this->findItem($request, $row['id']) : null;
            if ($item === null) {
                throw new PurchaseTransitionException(SpaApiError::PURCHASE_BINDING_INVALID);
            }

            // Снять «На складе» — значит купить позицию и поднять сумму. Здесь это не делаем:
            // раскладка пишется сразу, мимо проверки роста суммы в решении (approveTask).
            if (array_key_exists('inStock', $row)) {
                if (!is_bool($row['inStock'])) {
                    throw new PurchaseTransitionException(SpaApiError::PURCHASE_BINDING_INVALID);
                }
                if ($item->isInStock() && !$row['inStock']) {
                    throw new PurchaseTransitionException(SpaApiError::PURCHASE_STOCK_RELEASE_BY_DECISION);
                }
                $item->setInStock($row['inStock']);
            }
            if (array_key_exists('contractId', $row)) {
                $contract = $this->contractFromRow($request, $row['contractId']);
                if ($contract !== null && $item->isInStock()) {
                    throw new PurchaseTransitionException(SpaApiError::PURCHASE_STOCK_RELEASE_BY_DECISION);
                }
                $item->setContract($contract);
            }
            if (array_key_exists('invoiceFileId', $row)) {
                $invoice = $this->fileFromRow($request, $row['invoiceFileId'], PurchaseFileType::INVOICE);
                if ($invoice !== null && $item->isInStock()) {
                    throw new PurchaseTransitionException(SpaApiError::PURCHASE_STOCK_RELEASE_BY_DECISION);
                }
                $item->setInvoiceFile($invoice);
            }
            if ($item->isInStock()) {
                $item->setContract(null);
                $item->setInvoiceFile(null);
            }
        }
    }

    /**
     * Шаг «счета» можно закрыть: каждая закупаемая позиция оплачивается по
     * счёту, а если договоры есть — относится к одному из них.
     *
     * Неутверждённый договор — файл, которого ещё нет в справочнике. К нему
     * нужен подписанный скан. Ссылка на утверждённый договор шаг закрывает
     * сама: скан у него уже есть. Один неутверждённый договор закрывает и
     * скан без привязки — его отнесут к договору, когда появится запись.
     *
     * Всё на складе — ни счёта, ни подписанного договора: покупать нечего.
     *
     * @throws PurchaseTransitionException
     */
    public function assertComplete(PurchaseRequest $request): void
    {
        $toBuy = array_filter(
            $request->getItems()->toArray(),
            static fn (PurchaseRequestItem $item): bool => !$item->isExcluded() && !$item->isInStock(),
        );
        if ($toBuy === []) {
            return;
        }

        if (!$request->hasFileOfType(PurchaseFileType::INVOICE)) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_TASK_FILE_REQUIRED);
        }
        foreach ($toBuy as $item) {
            if ($item->getInvoiceFile() === null) {
                throw new PurchaseTransitionException(SpaApiError::PURCHASE_ITEMS_INVOICE_REQUIRED);
            }
        }
        // Быстрая заявка идёт без договора: шаг «Счёт» закрывает один счёт.
        if ($request->getCreatedAs() === PurchaseRequestKind::FAST) {
            return;
        }
        if (!$request->getContractLinks()->isEmpty()) {
            foreach ($toBuy as $item) {
                if ($item->getContract() === null) {
                    throw new PurchaseTransitionException(SpaApiError::PURCHASE_ITEMS_CONTRACT_REQUIRED);
                }
            }
        }
        if ($this->missingSignedScan($request)) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_TASK_FILE_REQUIRED);
        }
    }

    /**
     * Неутверждённому договору не хватает подписанного скана.
     * Утверждённая ссылка со сканом этот шаг не держит.
     */
    private function missingSignedScan(PurchaseRequest $request): bool
    {
        $unapproved = [];
        foreach ($request->getFiles() as $file) {
            $contract = $file->getContract();
            if ($file->getType() === PurchaseFileType::CONTRACT && ($contract === null || $contract->isDraft())) {
                $unapproved[] = $file;
            }
        }

        if ($unapproved === []) {
            return $request->getContractLinks()->isEmpty()
                && !$request->hasFileOfType(PurchaseFileType::SIGNED_CONTRACT);
        }

        $loose = 0;
        foreach ($request->getFiles() as $file) {
            if (
                $file->getType() === PurchaseFileType::SIGNED_CONTRACT
                && $file->getContractFile() === null
                && $file->getContract() === null
            ) {
                ++$loose;
            }
        }

        foreach ($unapproved as $contract) {
            $attached = false;
            foreach ($request->getFiles() as $scan) {
                if ($scan->getType() === PurchaseFileType::SIGNED_CONTRACT && $scan->getContractFile() === $contract) {
                    $attached = true;
                    break;
                }
            }
            if (!$attached && !(count($unapproved) === 1 && $loose > 0)) {
                return true;
            }
        }

        return false;
    }

    /** Итог раскладки для истории: «Счёт 1.pdf — 3 поз.; на складе — 1 поз.». */
    public function summary(PurchaseRequest $request): string
    {
        $counts = [];
        $inStock = 0;
        foreach ($request->getItems() as $item) {
            if ($item->isExcluded()) {
                continue;
            }
            if ($item->isInStock()) {
                ++$inStock;
                continue;
            }
            $name = (string) $item->getInvoiceFile()?->getOriginalName();
            if ($name !== '') {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        $parts = [];
        foreach ($counts as $name => $count) {
            $parts[] = sprintf('%s — %d поз.', $name, $count);
        }
        if ($inStock > 0) {
            $parts[] = sprintf('на складе — %d поз.', $inStock);
        }

        return implode('; ', $parts);
    }

    private function nextInvoiceColor(PurchaseRequest $request, PurchaseRequestFile $new): string
    {
        $used = [];
        $count = 0;
        foreach ($request->getFiles() as $file) {
            if ($file !== $new && $file->getType() === PurchaseFileType::INVOICE) {
                $used[(string) $file->getColor()] = true;
                ++$count;
            }
        }
        foreach (self::INVOICE_COLORS as $color) {
            if (!isset($used[$color])) {
                return $color;
            }
        }

        return self::INVOICE_COLORS[$count % count(self::INVOICE_COLORS)];
    }

    /** null — снять договор. Чужой для этой заявки договор — ошибка. */
    private function contractFromRow(PurchaseRequest $request, mixed $id): ?PurchaseContract
    {
        if ($id === null) {
            return null;
        }
        if (!is_int($id)) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_BINDING_INVALID);
        }
        foreach ($request->getContractLinks() as $link) {
            if ($link->getContract()->getId() === $id) {
                return $link->getContract();
            }
        }

        throw new PurchaseTransitionException(SpaApiError::PURCHASE_BINDING_INVALID);
    }

    /** null в запросе — снять привязку; чужой файл или не тот тип — ошибка. */
    private function fileFromRow(PurchaseRequest $request, mixed $id, PurchaseFileType $type): ?PurchaseRequestFile
    {
        if ($id === null) {
            return null;
        }
        $file = is_int($id) ? $this->findFile($request, $id, $type) : null;
        if ($file === null) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_BINDING_INVALID);
        }

        return $file;
    }

    private function findFile(PurchaseRequest $request, int $id, PurchaseFileType $type): ?PurchaseRequestFile
    {
        foreach ($request->getFiles() as $file) {
            if ($file->getId() === $id && $file->getType() === $type) {
                return $file;
            }
        }
        if ($type === PurchaseFileType::CONTRACT) {
            foreach ($request->sharedFilesOf($type) as $file) {
                if ($file->getId() === $id) {
                    return $file;
                }
            }
        }

        return null;
    }

    private function findItem(PurchaseRequest $request, int $id): ?PurchaseRequestItem
    {
        foreach ($request->getItems() as $item) {
            if ($item->getId() === $id) {
                return $item;
            }
        }

        return null;
    }
}
