<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\Purchase\PurchaseRequestItem;
use App\Enum\Purchase\PurchaseFileType;

/**
 * Раскладка позиций заявки по договорам и счетам.
 *
 * Большая закупка идёт по нескольким договорам, а по договору приходит несколько
 * счетов. Поэтому связь лежит на позиции: у каждой свой договор и свой счёт.
 * Счёт отдельно к договору не привязан — эта связь читается через позиции.
 *
 * Пока документ своего типа на заявке один, выбирать нечего: все позиции
 * относятся к нему сами. Выбор появляется со вторым документом.
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
        $this->applyDefaults($request);
    }

    /**
     * Позиции могли пересоздать: автор правил возвращённую заявку. Единственный
     * документ снова достаётся всем, ручную раскладку придётся повторить.
     */
    public function refresh(PurchaseRequest $request): void
    {
        $this->applyDefaults($request);
    }

    /**
     * Файл удаляют: позиции его теряют. Вызывать до удаления строки — внешний
     * ключ обнулит колонку в базе, но не в уже загруженных объектах.
     */
    public function onFileRemoved(PurchaseRequest $request, PurchaseRequestFile $file): void
    {
        foreach ($request->getItems() as $item) {
            if ($item->getContractFile() === $file) {
                $item->setContractFile(null);
            }
            if ($item->getInvoiceFile() === $file) {
                $item->setInvoiceFile(null);
            }
        }
        $this->applyDefaults($request, $file);
    }

    /**
     * Правки раскладки из модалки. Присланное поле меняется, остальное остаётся.
     *
     * @param list<array<string, mixed>> $itemRows {id, contractFileId?, invoiceFileId?, inStock?}
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

            if (array_key_exists('inStock', $row)) {
                if (!is_bool($row['inStock'])) {
                    throw new PurchaseTransitionException(SpaApiError::PURCHASE_BINDING_INVALID);
                }
                $item->setInStock($row['inStock']);
            }
            if (array_key_exists('contractFileId', $row)) {
                $item->setContractFile($this->fileFromRow($request, $row['contractFileId'], PurchaseFileType::CONTRACT));
            }
            if (array_key_exists('invoiceFileId', $row)) {
                $item->setInvoiceFile($this->fileFromRow($request, $row['invoiceFileId'], PurchaseFileType::INVOICE));
            }
            if ($item->isInStock()) {
                $item->setContractFile(null);
                $item->setInvoiceFile(null);
            }
        }

        $this->applyDefaults($request);
    }

    /**
     * Шаг «счета» можно закрыть: каждая закупаемая позиция оплачивается по
     * счёту, а если договоры есть — относится к одному из них.
     *
     * Всё на складе — счёт не нужен вовсе: покупать нечего.
     *
     * @throws PurchaseTransitionException
     */
    public function assertComplete(PurchaseRequest $request): void
    {
        $this->applyDefaults($request);

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
        $hasContract = $request->hasFileOfType(PurchaseFileType::CONTRACT);
        foreach ($toBuy as $item) {
            if ($item->getInvoiceFile() === null) {
                throw new PurchaseTransitionException(SpaApiError::PURCHASE_ITEMS_INVOICE_REQUIRED);
            }
            if ($hasContract && $item->getContractFile() === null) {
                throw new PurchaseTransitionException(SpaApiError::PURCHASE_ITEMS_CONTRACT_REQUIRED);
            }
        }
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

    /**
     * Единственный документ типа достаётся всем позициям без привязки.
     * Позиции со склада не трогаем: им документы не нужны.
     */
    private function applyDefaults(PurchaseRequest $request, ?PurchaseRequestFile $except = null): void
    {
        $contract = $this->onlyFile($request, PurchaseFileType::CONTRACT, $except);
        $invoice = $this->onlyFile($request, PurchaseFileType::INVOICE, $except);

        foreach ($request->getItems() as $item) {
            if ($item->isInStock()) {
                continue;
            }
            if ($contract !== null && $item->getContractFile() === null) {
                $item->setContractFile($contract);
            }
            if ($invoice !== null && $item->getInvoiceFile() === null) {
                $item->setInvoiceFile($invoice);
            }
        }
    }

    private function onlyFile(PurchaseRequest $request, PurchaseFileType $type, ?PurchaseRequestFile $except): ?PurchaseRequestFile
    {
        $found = [];
        foreach ($request->getFiles() as $file) {
            if ($file->getType() === $type && $file !== $except) {
                $found[] = $file;
            }
        }

        return count($found) === 1 ? $found[0] : null;
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
