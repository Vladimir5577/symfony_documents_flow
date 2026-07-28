<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Inventory;

use App\Entity\Inventory\BasisType;
use App\Entity\Inventory\Category;
use App\Entity\Inventory\CredentialViewLog;
use App\Entity\Inventory\Device;
use App\Entity\Inventory\DeviceCredential;
use App\Entity\Inventory\DeviceRepair;
use App\Entity\Inventory\Document;
use App\Entity\Inventory\DocumentFile;
use App\Entity\Inventory\DocumentLine;
use App\Entity\Inventory\ImportBatch;
use App\Entity\Inventory\ImportRow;
use App\Entity\Inventory\Movement;
use App\Entity\Inventory\Nomenclature;
use App\Entity\Inventory\Reconciliation;
use App\Entity\Inventory\ReconciliationLine;
use App\Entity\Inventory\Room;
use App\Entity\Inventory\Stock;
use App\Entity\Inventory\Warehouse;
use App\Entity\User\User;

/**
 * Ручная сборка JSON модуля инвентаризации (конвенция репо: без Serializer/Groups).
 * Формы ответов зафиксированы контрактом dev_docks/spa_api/inventory/Readme_inventory.md
 * и типами фронта analytics_platform/src/types/features/inventory.ts.
 */
final class InventoryApiPresenter
{
    /**
     * @return array{current_page: int, total_pages: int, total_items: int, items_per_page: int}
     */
    public function pagination(int $page, int $limit, int $total): array
    {
        return [
            'current_page' => $page,
            'total_pages' => (int) max(1, ceil($total / max(1, $limit))),
            'total_items' => $total,
            'items_per_page' => $limit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function category(Category $category): array
    {
        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'requires_device_card' => $category->isRequiresDeviceCard(),
            'sort' => $category->getSort(),
            'is_active' => $category->isActive(),
            'note' => null, // у категории нет примечания в схеме; ключ — для стабильности контракта
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function nomenclature(Nomenclature $nomenclature): array
    {
        $category = $nomenclature->getCategory();

        return [
            'id' => $nomenclature->getId(),
            'name' => $nomenclature->getName(),
            'category' => $category === null ? null : ['id' => $category->getId(), 'name' => $category->getName()],
            'unit' => [
                'value' => $nomenclature->getUnit()->value,
                'label' => $nomenclature->getUnit()->getLabel(),
            ],
            'is_consumable' => $nomenclature->isConsumable(),
            'code_1c' => $nomenclature->getCode1c(),
            'note' => $nomenclature->getNote(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function room(Room $room): array
    {
        return [
            'id' => $room->getId(),
            'name' => $room->getName(),
            'building' => $room->getBuilding(),
            'floor' => $room->getFloor(),
            'is_active' => $room->isActive(),
            'note' => $room->getNote(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function warehouse(Warehouse $warehouse): array
    {
        $department = $warehouse->getDepartment();
        $responsible = $warehouse->getResponsibleUser();

        return [
            'id' => $warehouse->getId(),
            'name' => $warehouse->getName(),
            'department' => $department === null ? null : ['id' => $department->getId(), 'name' => $department->getName()],
            'responsible' => $responsible === null ? null : ['id' => $responsible->getId(), 'fullName' => $this->fullName($responsible)],
            'is_active' => $warehouse->isActive(),
            'note' => $warehouse->getNote(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function basisType(BasisType $basisType): array
    {
        return [
            'id' => $basisType->getId(),
            'name' => $basisType->getName(),
            'is_active' => $basisType->isActive(),
        ];
    }

    /**
     * Строка остатков. В свёрнутых выборках (rollup=true) контур и кабинет не отдаются
     * (правило свёртки контракта).
     *
     * @return array<string, mixed>
     */
    public function stockRow(Stock $stock, bool $rollup = false): array
    {
        $holderUser = $stock->getHolderUser();
        $holderWarehouse = $stock->getHolderWarehouse();

        $row = [
            'nomenclature' => $this->nomenclature($stock->getNomenclature()),
            'holder' => $holderUser !== null
                ? ['type' => 'employee', 'id' => $holderUser->getId(), 'name' => $this->fullName($holderUser)]
                : ['type' => 'warehouse', 'id' => $holderWarehouse?->getId(), 'name' => (string) $holderWarehouse?->getName()],
            'quantity' => (string) $stock->getQuantity(),
        ];

        if (!$rollup) {
            $managing = $stock->getManagingWarehouse();
            $room = $stock->getRoom();
            $row['managingWarehouse'] = $managing === null ? null : ['id' => $managing->getId(), 'name' => $managing->getName()];
            $row['room'] = $room === null ? null : ['id' => $room->getId(), 'name' => $room->getName()];
        }

        return $row;
    }

    /**
     * Свёрнутая строка остатков из агрегата (SUM GROUP BY nomenclature, holder).
     *
     * @param array{nomenclature: Nomenclature, holderType: string, holderId: int, holderName: string, quantity: string} $aggregate
     *
     * @return array<string, mixed>
     */
    public function stockAggregate(array $aggregate): array
    {
        return [
            'nomenclature' => $this->nomenclature($aggregate['nomenclature']),
            'holder' => [
                'type' => $aggregate['holderType'],
                'id' => $aggregate['holderId'],
                'name' => $aggregate['holderName'],
            ],
            'quantity' => $aggregate['quantity'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function document(Document $document, bool $withLines = false): array
    {
        $data = [
            'id' => $document->getId(),
            'type' => ['value' => $document->getType()->value, 'label' => $document->getType()->getLabel()],
            'number' => $document->getNumber(),
            'doc_date' => $document->getDocDate()?->format('Y-m-d'),
            'status' => ['value' => $document->getStatus()->value, 'label' => $document->getStatus()->getLabel()],
            'external_number' => $document->getExternalNumber(),
            'external_date' => $document->getExternalDate()?->format('Y-m-d'),
            'supplier_name' => $document->getSupplierName(),
            'supplier_inn' => $document->getSupplierInn(),
            'basis_type' => $document->getBasisType() === null ? null : $this->basisType($document->getBasisType()),
            'reason' => $document->getReason(),
            'comment' => $document->getComment(),
            'reverses_document_id' => $document->getReversesDocument()?->getId(),
            'default_managing_warehouse_id' => $document->getDefaultManagingWarehouse()?->getId(),
            'created_by' => $this->userRef($document->getCreatedBy()),
            'posted_at' => $document->getPostedAt()?->format(\DateTimeInterface::ATOM),
            'posted_by' => $this->userRef($document->getPostedBy()),
            'created_at' => $document->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'files_count' => \count($document->getFiles()),
            'lines_count' => \count($document->getLines()),
        ];

        if ($withLines) {
            $data['lines'] = array_map($this->line(...), $document->getLines()->toArray());
            $data['files'] = array_map($this->file(...), $document->getFiles()->toArray());
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function line(DocumentLine $line): array
    {
        return [
            'id' => $line->getId(),
            'nomenclature' => $this->nomenclature($line->getNomenclature()),
            'quantity' => (string) $line->getQuantity(),
            'price' => $line->getPrice(),
            'from' => $this->side(
                $line->getFromHolderUser(),
                $line->getFromHolderWarehouse(),
                $line->getFromRoom()?->getId(),
                $line->getFromManagingWarehouse()?->getId(),
            ),
            'to' => $this->side(
                $line->getToHolderUser(),
                $line->getToHolderWarehouse(),
                $line->getToRoom()?->getId(),
                $line->getToManagingWarehouse()?->getId(),
            ),
            // Карточка устройства: отдаём id, чтобы привязку можно было и прочитать,
            // а не только отправить. Без этого поле было недостижимо в обе стороны.
            'device_id' => $line->getDevice()?->getId(),
            'note' => $line->getNote(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function file(DocumentFile $file): array
    {
        return [
            'id' => $file->getId(),
            'filename' => $file->getFilename(),
            'content_type' => $file->getContentType(),
            'size_bytes' => $file->getSizeBytes(),
            'created_at' => $file->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Батч импорта. Счётчики — контрольная сумма: recognized + rejected = expected.
     *
     * @return array<string, mixed>
     */
    public function importBatch(ImportBatch $batch): array
    {
        $format = $batch->getFormat();
        $status = $batch->getStatus();

        return [
            'id' => $batch->getId(),
            'filename' => $batch->getFilename(),
            'format' => $format === null ? null : ['value' => $format->value, 'label' => $format->getLabel()],
            'status' => ['value' => $status->value, 'label' => $status->getLabel()],
            'target_warehouse' => $batch->getTargetWarehouse() === null
                ? null
                : ['id' => $batch->getTargetWarehouse()->getId(), 'name' => $batch->getTargetWarehouse()->getName()],
            'totals' => [
                'expected' => $batch->getTotalsExpected(),
                'recognized' => $batch->getTotalsRecognized(),
                'rejected' => $batch->getTotalsRejected(),
            ],
            'created_by' => $this->userRef($batch->getCreatedBy()),
            'created_at' => $batch->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Строка staging. `raw_*` — как в файле, `matched_*` — во что сопоставили.
     * Обе стороны нужны оператору: он решает расхождение, глядя на исходник.
     *
     * @return array<string, mixed>
     */
    public function importRow(ImportRow $row): array
    {
        $rowType = $row->getRowType();
        $status = $row->getStatus();

        return [
            'id' => $row->getId(),
            'row_no' => $row->getRowNo(),
            'row_type' => $rowType === null ? null : ['value' => $rowType->value, 'label' => $rowType->getLabel()],
            'status' => ['value' => $status->value, 'label' => $status->getLabel()],
            'raw_name' => $row->getRawName(),
            'raw_fio' => $row->getRawFio(),
            'raw_subdivision' => $row->getRawSubdivision(),
            'quantity' => $row->getQuantity(),
            'problem' => $row->getProblem(),
            'matched_nomenclature' => $row->getMatchedNomenclature() === null
                ? null
                : $this->nomenclature($row->getMatchedNomenclature()),
            'matched_user' => $this->userRef($row->getMatchedUser()),
        ];
    }

    /**
     * Сверка с 1С. `max_movement_id` отдаём наружу намеренно: он объясняет,
     * почему пересчёт той же сверки даёт тот же результат после новых проведений.
     *
     * @return array<string, mixed>
     */
    public function reconciliation(Reconciliation $reconciliation): array
    {
        $status = $reconciliation->getStatus();
        $warehouse = $reconciliation->getWarehouse();

        return [
            'id' => $reconciliation->getId(),
            'title' => $reconciliation->getTitle(),
            'as_of_date' => $reconciliation->getAsOfDate()?->format('Y-m-d'),
            'warehouse' => $warehouse === null
                ? null
                : ['id' => $warehouse->getId(), 'name' => $warehouse->getName()],
            'status' => ['value' => $status->value, 'label' => $status->getLabel()],
            'max_movement_id' => $reconciliation->getMaxMovementId(),
            'lines_count' => \count($reconciliation->getLines()),
            'created_by' => $this->userRef($reconciliation->getCreatedBy()),
            'created_at' => $reconciliation->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Строка сверки. `raw_name` — как в файле 1С, `nomenclature` — во что сопоставили;
     * обе стороны нужны, иначе расхождение нечем объяснить.
     *
     * @return array<string, mixed>
     */
    public function reconciliationLine(ReconciliationLine $line): array
    {
        $comparison = $line->getComparisonStatus();
        $resolution = $line->getResolutionStatus();

        return [
            'id' => $line->getId(),
            'raw_name' => $line->getRawName(),
            'raw_subdivision' => $line->getRawSubdivision(),
            'nomenclature' => $line->getNomenclature() === null
                ? null
                : $this->nomenclature($line->getNomenclature()),
            'qty_1c' => $line->getQty1c(),
            'qty_system' => $line->getQtySystem(),
            'comparison_status' => $comparison === null
                ? null
                : ['value' => $comparison->value, 'label' => $comparison->getLabel()],
            'resolution_status' => ['value' => $resolution->value, 'label' => $resolution->getLabel()],
            'note' => $line->getNote(),
        ];
    }

    /**
     * Карточка-паспорт устройства.
     *
     * ⚠ Сетевые поля (`ip_address`, `mac_address`, `hostname`) отдаются ТОЛЬКО при
     * `$withNetwork = true` — иначе ключи присутствуют со значением `null`. Ключи не
     * выкидываются намеренно: клиенту проще отличить «нет доступа» от «поле не заполнено»
     * по отдельному флагу `network_visible`, чем по отсутствию ключа.
     *
     * @param array{holder: array<string, mixed>|null, room: array<string, mixed>|null, source: string}|null $location
     *
     * @return array<string, mixed>
     */
    public function device(Device $device, bool $withNetwork, ?array $location = null): array
    {
        $status = $device->getStatus();
        $nomenclature = $device->getNomenclature();

        return [
            'id' => $device->getId(),
            'name' => $device->getName(),
            'nomenclature' => $nomenclature === null ? null : $this->nomenclature($nomenclature),
            'inventory_number' => $device->getInventoryNumber(),
            'serial_number' => $device->getSerialNumber(),
            'status' => ['value' => $status->value, 'label' => $status->getLabel()],
            'network_visible' => $withNetwork,
            'ip_address' => $withNetwork ? $device->getIpAddress() : null,
            'mac_address' => $withNetwork ? $device->getMacAddress() : null,
            'hostname' => $withNetwork ? $device->getHostname() : null,
            'manual_location' => $device->getManualLocation(),
            'location' => $location,
            'specs' => $device->getSpecs(),
            'note' => $device->getNote(),
            'credentials_count' => \count($device->getCredentials()),
            'repairs_count' => \count($device->getRepairs()),
            'created_at' => $device->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $device->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Доступ к устройству БЕЗ секрета. Логин и пароль лежат в `secret_cipher` единым
     * envelope и наружу попадают только через reveal — здесь их нет и быть не должно.
     * `is_wiped` — доступ был удалён при непустом журнале просмотров: строку удалить нельзя
     * (RESTRICT), поэтому затирается сам секрет, а запись остаётся как след для аудита.
     *
     * @return array<string, mixed>
     */
    public function credential(DeviceCredential $credential): array
    {
        $kind = $credential->getKind();

        return [
            'id' => $credential->getId(),
            'device_id' => $credential->getDevice()?->getId(),
            'kind' => ['value' => $kind->value, 'label' => $kind->getLabel()],
            'title' => $credential->getTitle(),
            'url' => $credential->getUrl(),
            'key_version' => $credential->getKeyVersion(),
            'is_wiped' => trim((string) $credential->getSecretCipher()) === '',
            'note' => $credential->getNote(),
            'created_at' => $credential->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $credential->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function repair(DeviceRepair $repair): array
    {
        return [
            'id' => $repair->getId(),
            'device_id' => $repair->getDevice()?->getId(),
            'repair_date' => $repair->getRepairDate()?->format('Y-m-d'),
            'description' => $repair->getDescription(),
            'cost' => $repair->getCost(),
            'performed_by' => $repair->getPerformedBy(),
            'created_by' => $this->userRef($repair->getCreatedBy()),
            'created_at' => $repair->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Запись журнала просмотров доступа. Названия денормализованы в самой записи:
     * журнал обязан пережить удаление источника, иначе аудит теряет смысл.
     *
     * @return array<string, mixed>
     */
    public function credentialViewLog(CredentialViewLog $log): array
    {
        return [
            'id' => $log->getId(),
            'credential_id' => $log->getCredential()?->getId(),
            'credential_title' => $log->getCredentialTitle(),
            'device_name' => $log->getDeviceName(),
            'user' => $this->userRef($log->getUser()),
            'created_at' => $log->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Движение ТМЦ. Используется вкладками устройства (перемещения и расход материалов)
     * и отчётами Ф5.
     *
     * @return array<string, mixed>
     */
    public function movement(Movement $movement): array
    {
        $document = $movement->getDocument();
        $holderUser = $movement->getHolderUser();
        $holderWarehouse = $movement->getHolderWarehouse();

        return [
            'id' => $movement->getId(),
            'document' => $document === null ? null : [
                'id' => $document->getId(),
                'number' => $document->getNumber(),
                'type' => ['value' => $document->getType()->value, 'label' => $document->getType()->getLabel()],
            ],
            'nomenclature' => $this->nomenclature($movement->getNomenclature()),
            // +1 приход в разрез, -1 расход из разреза; знак нужен клиенту, чтобы
            // не гадать по типу документа.
            'direction' => $movement->getDirection(),
            'quantity' => (string) $movement->getQuantity(),
            'holder' => $holderUser !== null
                ? ['type' => 'employee', 'id' => $holderUser->getId(), 'name' => $this->fullName($holderUser)]
                : ($holderWarehouse === null ? null : ['type' => 'warehouse', 'id' => $holderWarehouse->getId(), 'name' => $holderWarehouse->getName()]),
            'room' => $movement->getRoom() === null
                ? null
                : ['id' => $movement->getRoom()->getId(), 'name' => $movement->getRoom()->getName()],
            'device_id' => $movement->getDevice()?->getId(),
            'operation_date' => $movement->getOperationDate()?->format('Y-m-d'),
        ];
    }

    /**
     * @return array{id: int|null, fullName: string}|null
     */
    private function userRef(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->getId(), 'fullName' => $this->fullName($user)];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function side(?User $user, ?Warehouse $warehouse, ?int $roomId, ?int $managingId): ?array
    {
        if ($user === null && $warehouse === null) {
            return null;
        }

        return [
            'holder' => $user !== null
                ? ['type' => 'employee', 'id' => $user->getId(), 'name' => $this->fullName($user)]
                : ['type' => 'warehouse', 'id' => $warehouse?->getId(), 'name' => (string) $warehouse?->getName()],
            'room_id' => $roomId,
            'managing_warehouse_id' => $managingId,
        ];
    }

    private function fullName(User $user): string
    {
        return trim(implode(' ', array_filter([
            $user->getLastname(),
            $user->getFirstname(),
            $user->getPatronymic(),
        ])));
    }
}
