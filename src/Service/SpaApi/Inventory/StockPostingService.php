<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Document;
use App\Entity\Inventory\DocumentLine;
use App\Entity\Inventory\Movement;
use App\Entity\User\User;
use App\Enum\Inventory\DocumentStatus;
use App\Enum\Inventory\DocumentType;
use App\Repository\Inventory\DeviceRepository;
use App\Repository\Inventory\DocCounterRepository;
use App\Repository\Inventory\StockRepository;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Проведение и сторнирование документов инвентаризации — единственная точка изменения остатков.
 *
 * Ledger-инварианты (CLAUDE.md):
 *  - inventory_movement — append-only; исправления только сторно-документом;
 *  - inventory_stock меняется атомарным UPSERT'ом; CHECK quantity >= 0 ловит уход в минус;
 *  - после posted документ/строки/сканы неизменяемы.
 *
 * Конкурентность (по итогам состязательного ревью 2026-07-28):
 *  - документ блокируется PESSIMISTIC_WRITE в транзакции, статус перепроверяется после
 *    блокировки — двойное проведение/сторно исключено (TOCTOU закрыт);
 *  - UPSERT'ы остатков выполняются в детерминированном порядке ключей — дедлоки между
 *    документами исключаются упорядочением блокировок;
 *  - остаточные serialization/deadlock конфликты не ретраятся внутри (EM закрывается
 *    Doctrine при исключении в транзакции — ретрай с тем же EM невозможен), а мапятся
 *    в 409 INVENTORY_CONCURRENT_RETRY: клиент повторяет операцию.
 *
 * Скоуп МОЛа: InventoryAccessService — ранний гейт; ОКОНЧАТЕЛЬНАЯ проверка контура —
 * здесь, после авторезолва from-стороны ($allowedWarehouseIds; null — без ограничений).
 */
final class StockPostingService
{
    /** SQLSTATE: сериализация/дедлок → 409, повторить */
    private const RETRYABLE_SQLSTATES = ['40001', '40P01'];
    private const CHECK_VIOLATION_SQLSTATE = '23514';
    private const UNIQUE_VIOLATION_SQLSTATE = '23505';

    /** Масштаб количеств: DECIMAL(14,3). Сравнение — в целых тысячных, без float-погрешности. */
    private const QTY_SCALE = 1000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocCounterRepository $counters,
        private readonly StockRepository $stocks,
        private readonly DeviceRepository $devices,
        private readonly InventoryHistoryService $history,
    ) {
    }

    /**
     * @param list<int>|null $allowedWarehouseIds контур актора (null = админ, без ограничений)
     *
     * @return list<array<string, mixed>> некритичные предупреждения проведения (например, карточек
     *                      устройств больше, чем предметов на остатке). Пустой список — замечаний нет.
     */
    public function post(Document $document, User $actor, ?array $allowedWarehouseIds = null): array
    {
        if ($document->getType() === DocumentType::REVERSAL) {
            // Сторно создаётся и проводится только через reverse()
            throw new BadRequestHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_STATUS);
        }

        $warnings = [];

        try {
            $this->em->wrapInTransaction(function () use ($document, $actor, $allowedWarehouseIds, &$warnings): void {
                // TOCTOU-защита: блокируем строку документа и перечитываем состояние
                $this->em->lock($document, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($document);

                if ($document->getStatus() !== DocumentStatus::DRAFT) {
                    throw new ConflictHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_STATUS);
                }

                $warnings = $this->assertRequisites($document);

                $number = (string) $document->getNumber();
                if ($number === '' || str_starts_with($number, Document::DRAFT_NUMBER_PREFIX)) {
                    // черновики носят временный номер ЧЕРН-…; настоящий — из счётчика (FOR UPDATE)
                    $document->setNumber($this->generateNumber($document));
                }

                $specs = [];
                foreach ($document->getLines() as $line) {
                    foreach ($this->buildMovementSpecs($document, $line, $allowedWarehouseIds) as $spec) {
                        $this->persistMovement($document, $line, $spec);
                        $specs[] = $spec;
                    }
                }

                foreach ($this->sortSpecs($specs) as $spec) {
                    $this->applyToStock($spec);
                }

                $document->setStatus(DocumentStatus::POSTED);
                $document->setPostedAt(new \DateTimeImmutable());
                $document->setPostedBy($actor);

                $this->history->log('document', (int) $document->getId(), 'post', $actor, [
                    'type' => $document->getType()->value,
                    'number' => $document->getNumber(),
                ]);

                $this->em->flush();
            });
        } catch (DriverException $e) {
            throw $this->mapDriverException($e);
        }

        return $warnings;
    }

    /**
     * Сторно: оригинал → reversed, создаётся зеркальный документ reversal с инверсией движений.
     * Сторно сторно-документа запрещено; для не-админа все затронутые контуры обязаны
     * принадлежать его складам.
     *
     * @param list<int>|null $allowedWarehouseIds
     */
    public function reverse(Document $original, User $actor, ?array $allowedWarehouseIds = null): Document
    {
        if ($original->getType() === DocumentType::REVERSAL) {
            throw new ConflictHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_STATUS);
        }

        $reversal = new Document();
        $reversal->setType(DocumentType::REVERSAL);
        $reversal->setDocDate(new \DateTimeImmutable());
        $reversal->setStatus(DocumentStatus::DRAFT);
        $reversal->setReversesDocument($original);
        $reversal->setCreatedBy($actor);

        try {
            $this->em->wrapInTransaction(function () use ($original, $reversal, $actor, $allowedWarehouseIds): void {
                $this->em->lock($original, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($original);

                if ($original->getStatus() !== DocumentStatus::POSTED) {
                    throw new ConflictHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_STATUS);
                }

                $originalMovements = $this->em->getRepository(Movement::class)->findBy(['document' => $original]);
                if ($originalMovements === []) {
                    throw new ConflictHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_STATUS);
                }

                $specs = [];
                foreach ($originalMovements as $movement) {
                    $spec = MovementSpec::fromMovementInverted($movement);
                    $this->assertSpecWithinScope($spec, $allowedWarehouseIds);
                    $specs[] = [$spec, $movement->getDocumentLine()];
                }

                $reversal->setComment(sprintf('Сторно документа %s', (string) $original->getNumber()));
                $reversal->setNumber($this->generateNumber($reversal));
                $this->em->persist($reversal);

                foreach ($specs as [$spec, $line]) {
                    $this->persistMovement($reversal, $line, $spec);
                }

                foreach ($this->sortSpecs(array_column($specs, 0)) as $spec) {
                    try {
                        $this->applyToStock($spec);
                    } catch (BadRequestHttpException $e) {
                        if ($e->getMessage() === SpaApiError::INVENTORY_INSUFFICIENT_STOCK) {
                            throw new ConflictHttpException(SpaApiError::INVENTORY_REVERSAL_WOULD_GO_NEGATIVE);
                        }
                        throw $e;
                    }
                }

                $reversal->setStatus(DocumentStatus::POSTED);
                $reversal->setPostedAt(new \DateTimeImmutable());
                $reversal->setPostedBy($actor);
                $original->setStatus(DocumentStatus::REVERSED);

                $this->history->log('document', (int) $original->getId(), 'reverse', $actor, [
                    'reversal_number' => $reversal->getNumber(),
                ]);

                $this->em->flush();
            });
        } catch (DriverException $e) {
            throw $this->mapDriverException($e);
        }

        return $reversal;
    }

    // ------------------------------------------------------------------ validation

    /**
     * @return list<array<string, mixed>> некритичные предупреждения (не блокируют проведение)
     */
    private function assertRequisites(Document $document): array
    {
        $lines = $document->getLines();
        if (\count($lines) === 0) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_LINES);
        }

        $type = $document->getType();

        if ($type === DocumentType::RECEIPT_UPD) {
            $missing = $document->getExternalNumber() === null || trim((string) $document->getExternalNumber()) === ''
                || $document->getExternalDate() === null
                || $document->getSupplierName() === null || trim((string) $document->getSupplierName()) === '';
            if ($missing) {
                throw new BadRequestHttpException(SpaApiError::INVENTORY_DOCUMENT_REQUISITES_REQUIRED);
            }
            $this->assertHasFile($document);
        }

        if ($type === DocumentType::WRITEOFF) {
            if ($document->getBasisType() === null || $document->getReason() === null || trim((string) $document->getReason()) === '') {
                throw new BadRequestHttpException(SpaApiError::INVENTORY_DOCUMENT_REQUISITES_REQUIRED);
            }
            $this->assertHasFile($document);
        }

        if ($type === DocumentType::CONSUMPTION) {
            foreach ($lines as $line) {
                if (!$line->getNomenclature()->isConsumable()) {
                    throw new BadRequestHttpException(SpaApiError::INVENTORY_CONSUMABLE_ONLY);
                }
            }
        }

        $warnings = [];
        foreach ($lines as $line) {
            foreach ($this->assertDeviceBinding($type, $line) as $warning) {
                // Одна позиция может идти несколькими строками — дублировать замечание незачем.
                $warnings[$warning['code'] . ':' . $warning['nomenclature_id']] = $warning;
            }
            $this->assertLineShape($type, $line);
        }

        return array_values($warnings);
    }

    /**
     * Привязка строки к карточке устройства (Ф4).
     *
     * Смысл `device_id` зависит от типа документа и это НЕ одно и то же:
     * - `transfer` / `writeoff` — карточка и есть перемещаемый (списываемый) предмет,
     *   поэтому количество строго 1 и номенклатура строки обязана совпасть с номенклатурой карточки;
     * - `consumption` — устройство-ПОТРЕБИТЕЛЬ (картридж В принтер), номенклатуры заведомо
     *   разные и сравнивать их нельзя, количество произвольное.
     *
     * Остальные типы карточку не принимают: у прихода и ввода остатков предмет ещё не
     * персонифицирован (карточка заводится отдельно), сторно собирается из движений оригинала.
     *
     * @return list<array{code: string, nomenclature_id: int, active_cards: int, stock: string}>
     *         предупреждения; блокирующие случаи бросают исключение
     */
    private function assertDeviceBinding(DocumentType $type, DocumentLine $line): array
    {
        $device = $line->getDevice();
        if ($device === null) {
            return [];
        }

        if (!\in_array($type, [DocumentType::TRANSFER, DocumentType::WRITEOFF, DocumentType::CONSUMPTION], true)) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_DEVICE_NOT_SUPPORTED);
        }

        if ($type === DocumentType::CONSUMPTION) {
            return [];
        }

        // Карточка — конкретный физический предмет: половину ноутбука не переместить.
        if ($this->toThousandths((string) $line->getQuantity()) !== self::QTY_SCALE) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_LINES);
        }

        // Иначе перемещением мыши можно было бы «переехать» карточкой ноутбука.
        $nomenclatureId = (int) $line->getNomenclature()->getId();
        if ((int) $device->getNomenclature()?->getId() !== $nomenclatureId) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_LINES);
        }

        // Не блок, а сигнал: карточек больше, чем предметов на остатке, — где-то задвоили
        // паспорт или не провели списание. Останавливать из-за этого проведение нельзя,
        // иначе учёт встанет из-за ошибки в справочнике.
        $activeCards = $this->devices->countActiveByNomenclature($nomenclatureId);
        $stock = $this->stocks->sumQuantityByNomenclature($nomenclatureId);
        if ($activeCards * self::QTY_SCALE > $this->toThousandths($stock)) {
            return [[
                'code' => 'inventory_device_cards_exceed_stock',
                'nomenclature_id' => $nomenclatureId,
                'active_cards' => $activeCards,
                'stock' => $stock,
            ]];
        }

        return [];
    }

    /**
     * Количество в целых тысячных: масштаб схемы — DECIMAL(14,3).
     * Сравнивать decimal-строки напрямую нельзя («1» и «1.000» — разные строки при равных
     * количествах), float даёт погрешность.
     */
    private function toThousandths(string $quantity): int
    {
        $normalized = trim(str_replace(',', '.', $quantity));
        if ($normalized === '') {
            return 0;
        }

        return (int) round((float) $normalized * self::QTY_SCALE);
    }

    private function assertHasFile(Document $document): void
    {
        if (\count($document->getFiles()) === 0) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_FILE_REQUIRED);
        }
    }

    private function assertLineShape(DocumentType $type, DocumentLine $line): void
    {
        $hasFrom = $line->getFromHolderUser() !== null || $line->getFromHolderWarehouse() !== null;
        $hasTo = $line->getToHolderUser() !== null || $line->getToHolderWarehouse() !== null;
        $fromXor = ($line->getFromHolderUser() !== null) xor ($line->getFromHolderWarehouse() !== null);
        $toXor = ($line->getToHolderUser() !== null) xor ($line->getToHolderWarehouse() !== null);

        $ok = match ($type) {
            // приход — только на склад
            DocumentType::RECEIPT_UPD => !$hasFrom && $line->getToHolderWarehouse() !== null && $line->getToHolderUser() === null,
            // ввод остатков — на склад или на сотрудника
            DocumentType::INITIAL_BALANCE => !$hasFrom && $hasTo && $toXor,
            // перемещение — обе стороны
            DocumentType::TRANSFER => $hasFrom && $fromXor && $hasTo && $toXor,
            // расход — откуда списываем; to_holder_user допустим справочно (получатель)
            DocumentType::CONSUMPTION => $hasFrom && $fromXor && $line->getToHolderWarehouse() === null,
            // списание — только откуда
            DocumentType::WRITEOFF => $hasFrom && $fromXor && !$hasTo,
            DocumentType::REVERSAL => false,
        };

        if (!$ok) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_LINES);
        }
    }

    // ------------------------------------------------------------------ movements

    /**
     * @param list<int>|null $allowedWarehouseIds
     *
     * @return list<MovementSpec>
     */
    private function buildMovementSpecs(Document $document, DocumentLine $line, ?array $allowedWarehouseIds): array
    {
        $specs = [];
        $type = $document->getType();
        $quantity = (string) $line->getQuantity();
        $nomenclatureId = (int) $line->getNomenclature()->getId();
        $operationDate = $document->getDocDate();
        $deviceId = $line->getDevice()?->getId();

        // -1: сторона-источник (контур источника обязан принадлежать актору — финальный гейт скоупа)
        if ($line->getFromHolderUser() !== null || $line->getFromHolderWarehouse() !== null) {
            $from = $this->resolveFromCut($line);
            $spec = new MovementSpec(
                nomenclatureId: $nomenclatureId,
                direction: -1,
                quantity: $quantity,
                holderUserId: $from['holderUserId'],
                holderWarehouseId: $from['holderWarehouseId'],
                managingWarehouseId: $from['managingWarehouseId'],
                roomId: $from['roomId'],
                holderDepartmentId: $this->departmentSnapshot($line->getFromHolderUser()),
                operationDate: $operationDate,
                deviceId: $deviceId,
            );
            $this->assertSpecWithinScope($spec, $allowedWarehouseIds);
            $specs[] = $spec;
        }

        // +1: сторона-приёмник (у consumption её нет — расход уничтожает остаток)
        $needsIncoming = \in_array($type, [DocumentType::RECEIPT_UPD, DocumentType::INITIAL_BALANCE, DocumentType::TRANSFER], true);
        if ($needsIncoming) {
            $toUser = $line->getToHolderUser();
            $toWarehouse = $line->getToHolderWarehouse();
            $managingId = null;
            if ($toUser !== null) {
                $managingId = $this->resolveToManaging($document, $line, $specs);
            }
            $spec = new MovementSpec(
                nomenclatureId: $nomenclatureId,
                direction: 1,
                quantity: $quantity,
                holderUserId: $toUser?->getId(),
                holderWarehouseId: $toWarehouse?->getId(),
                managingWarehouseId: $managingId,
                roomId: $line->getToRoom()?->getId(),
                holderDepartmentId: $this->departmentSnapshot($toUser),
                operationDate: $operationDate,
                deviceId: $deviceId,
            );
            // Приход/ввод БЕЗ from-стороны обязан попадать в собственный контур актора;
            // transfer НА чужой склад разрешён (проводит отправитель).
            //
            // ⚠ Это НЕ пропущенная проверка, а решение: требуй мы контур и на приёмной
            // стороне, межскладские перемещения стали бы невозможны — МОЛ мог бы двигать
            // ТМЦ только внутри своих складов. Вектора хищения тут нет: from-сторона
            // проверяется всегда, то есть увезти можно только СВОЁ, а у склада-получателя
            // остаток лишь растёт. Подтверждение приёмки — отдельная задача из бэклога.
            // Ревью Codex 2026-07-28 подняло это как BLOCKER — отклонено по этой причине.
            if ($specs === []) {
                $this->assertSpecWithinScope($spec, $allowedWarehouseIds);
            }
            $specs[] = $spec;
        }

        return $specs;
    }

    /**
     * Контур спецификации: склад-держатель либо managing-склад сотрудника.
     *
     * @param list<int>|null $allowedWarehouseIds
     */
    private function assertSpecWithinScope(MovementSpec $spec, ?array $allowedWarehouseIds): void
    {
        if ($allowedWarehouseIds === null) {
            return; // админ
        }

        $scopeWarehouseId = $spec->holderWarehouseId ?? $spec->managingWarehouseId;
        if ($scopeWarehouseId === null || !\in_array($scopeWarehouseId, $allowedWarehouseIds, true)) {
            throw new AccessDeniedHttpException(SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE);
        }
    }

    /**
     * Разрез-источник. Контур (и кабинет) для держателя-сотрудника авторезолвится,
     * если подходящая строка остатка ровно одна; иначе требуется явное указание
     * from_managing_warehouse_id / from_room_id в строке документа.
     *
     * @return array{holderUserId: ?int, holderWarehouseId: ?int, managingWarehouseId: ?int, roomId: ?int}
     */
    private function resolveFromCut(DocumentLine $line): array
    {
        $fromUser = $line->getFromHolderUser();
        $fromWarehouse = $line->getFromHolderWarehouse();
        $nomenclatureId = (int) $line->getNomenclature()->getId();

        if ($fromWarehouse !== null) {
            return [
                'holderUserId' => null,
                'holderWarehouseId' => (int) $fromWarehouse->getId(),
                'managingWarehouseId' => null, // у складского остатка контур = сам склад (CHECK)
                'roomId' => $this->resolveFromRoom($line, $nomenclatureId, null, (int) $fromWarehouse->getId(), null),
            ];
        }

        $explicitManaging = $line->getFromManagingWarehouse()?->getId();
        if ($explicitManaging !== null) {
            return [
                'holderUserId' => (int) $fromUser->getId(),
                'holderWarehouseId' => null,
                'managingWarehouseId' => (int) $explicitManaging,
                'roomId' => $this->resolveFromRoom($line, $nomenclatureId, (int) $fromUser->getId(), null, (int) $explicitManaging),
            ];
        }

        // авторезолв: единственная строка остатка сотрудника по номенклатуре (+кабинет, если указан).
        // Контур результата затем проверяется assertSpecWithinScope — чужой контур не спишется.
        $candidates = $this->stocks->findPositiveByHolderUser(
            nomenclatureId: $nomenclatureId,
            holderUserId: (int) $fromUser->getId(),
            roomId: $line->getFromRoom()?->getId(),
        );

        if (\count($candidates) === 0) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_INSUFFICIENT_STOCK);
        }
        if (\count($candidates) > 1) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_LINES);
        }

        $stock = $candidates[0];

        return [
            'holderUserId' => (int) $fromUser->getId(),
            'holderWarehouseId' => null,
            'managingWarehouseId' => $stock->getManagingWarehouse() !== null ? (int) $stock->getManagingWarehouse()->getId() : null,
            'roomId' => $stock->getRoom()?->getId(),
        ];
    }

    private function resolveFromRoom(DocumentLine $line, int $nomenclatureId, ?int $holderUserId, ?int $holderWarehouseId, ?int $managingId): ?int
    {
        if ($line->getFromRoom() !== null) {
            return (int) $line->getFromRoom()->getId();
        }

        // кабинет не указан: если остаток лежит ровно в одном разрезе — берём его кабинет
        $candidates = $holderWarehouseId !== null
            ? $this->stocks->findPositiveByHolderWarehouse($nomenclatureId, $holderWarehouseId)
            : $this->stocks->findPositiveByHolderUser($nomenclatureId, (int) $holderUserId, null, $managingId);

        if (\count($candidates) === 1) {
            return $candidates[0]->getRoom()?->getId();
        }
        if (\count($candidates) === 0) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_INSUFFICIENT_STOCK);
        }

        throw new BadRequestHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_LINES);
    }

    /**
     * Контур для to-стороны с держателем-сотрудником:
     * явный в строке → склад-источник (transfer со склада) → сохранение контура (transfer от сотрудника)
     * → дефолт документа. Иначе — ошибка.
     *
     * @param list<MovementSpec> $builtSpecs уже построенная from-сторона этой строки
     */
    private function resolveToManaging(Document $document, DocumentLine $line, array $builtSpecs): int
    {
        if ($line->getToManagingWarehouse() !== null) {
            return (int) $line->getToManagingWarehouse()->getId();
        }

        if ($line->getFromHolderWarehouse() !== null) {
            return (int) $line->getFromHolderWarehouse()->getId();
        }

        if ($line->getFromHolderUser() !== null && $builtSpecs !== []) {
            $fromManaging = $builtSpecs[0]->managingWarehouseId;
            if ($fromManaging !== null) {
                return $fromManaging; // user→user: контур сохраняется
            }
        }

        $default = $document->getDefaultManagingWarehouse();
        if ($default !== null) {
            return (int) $default->getId();
        }

        throw new BadRequestHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_LINES);
    }

    private function departmentSnapshot(?User $user): ?int
    {
        return $user?->getOrganization()?->getId();
    }

    private function persistMovement(Document $document, ?DocumentLine $line, MovementSpec $spec): void
    {
        $movement = new Movement();
        $movement->setDocument($document);
        if ($line !== null) {
            $movement->setDocumentLine($line);
        }
        $movement->setNomenclature($this->em->getReference(\App\Entity\Inventory\Nomenclature::class, $spec->nomenclatureId));
        $movement->setDirection($spec->direction);
        $movement->setQuantity($spec->quantity);
        $movement->setHolderUser($spec->holderUserId !== null ? $this->em->getReference(User::class, $spec->holderUserId) : null);
        $movement->setHolderWarehouse($spec->holderWarehouseId !== null ? $this->em->getReference(\App\Entity\Inventory\Warehouse::class, $spec->holderWarehouseId) : null);
        $movement->setManagingWarehouse($spec->managingWarehouseId !== null ? $this->em->getReference(\App\Entity\Inventory\Warehouse::class, $spec->managingWarehouseId) : null);
        $movement->setRoom($spec->roomId !== null ? $this->em->getReference(\App\Entity\Inventory\Room::class, $spec->roomId) : null);
        $movement->setHolderDepartmentId($spec->holderDepartmentId);
        $movement->setOperationDate($spec->operationDate);
        $movement->setDevice(
            $spec->deviceId !== null
                ? $this->em->getReference(\App\Entity\Inventory\Device::class, $spec->deviceId)
                : null,
        );

        $this->em->persist($movement);
    }

    // ------------------------------------------------------------------ stock upsert

    /**
     * Детерминированный порядок применения — блокировки строк остатков берутся всеми
     * транзакциями в одном порядке, междокументные дедлоки исключаются.
     *
     * @param list<MovementSpec> $specs
     *
     * @return list<MovementSpec>
     */
    private function sortSpecs(array $specs): array
    {
        usort($specs, static fn (MovementSpec $a, MovementSpec $b): int => $a->stockKey() <=> $b->stockKey());

        return $specs;
    }

    private function applyToStock(MovementSpec $spec): void
    {
        $delta = $spec->direction === 1 ? $spec->quantity : '-' . $spec->quantity;

        $sql = <<<'SQL'
            INSERT INTO inventory_stock
                (nomenclature_id, holder_user_id, holder_warehouse_id, managing_warehouse_id, room_id, quantity)
            VALUES (:nomenclature, :holderUser, :holderWarehouse, :managing, :room, CAST(:delta AS NUMERIC(14,3)))
            ON CONFLICT ON CONSTRAINT uniq_inv_stock_key
            DO UPDATE SET quantity = inventory_stock.quantity + EXCLUDED.quantity
            SQL;

        try {
            $this->em->getConnection()->executeStatement($sql, [
                'nomenclature' => $spec->nomenclatureId,
                'holderUser' => $spec->holderUserId,
                'holderWarehouse' => $spec->holderWarehouseId,
                'managing' => $spec->managingWarehouseId,
                'room' => $spec->roomId,
                'delta' => $delta,
            ]);
        } catch (DriverException $e) {
            if ($e->getSQLState() === self::CHECK_VIOLATION_SQLSTATE) {
                throw new BadRequestHttpException(SpaApiError::INVENTORY_INSUFFICIENT_STOCK, $e);
            }
            throw $e;
        }
    }

    private function generateNumber(Document $document): string
    {
        $year = (int) $document->getDocDate()->format('Y');
        $next = $this->counters->nextNumber($document->getType(), $year);

        return sprintf('%s-%d-%04d', $document->getType()->numberPrefix(), $year, $next);
    }

    private function mapDriverException(DriverException $e): \Throwable
    {
        if (\in_array($e->getSQLState(), self::RETRYABLE_SQLSTATES, true)) {
            // конкурирующая операция; EM закрыт Doctrine — повтор возможен только новым запросом
            return new ConflictHttpException(SpaApiError::INVENTORY_CONCURRENT_RETRY, $e);
        }
        if ($e->getSQLState() === self::UNIQUE_VIOLATION_SQLSTATE) {
            // например, второй параллельный reverse одного оригинала (uniq reverses_document_id)
            return new ConflictHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_STATUS, $e);
        }

        return $e;
    }
}

/**
 * Внутренняя спецификация одного движения (разрез + направление + количество).
 */
final readonly class MovementSpec
{
    public function __construct(
        public int $nomenclatureId,
        public int $direction,
        public string $quantity,
        public ?int $holderUserId,
        public ?int $holderWarehouseId,
        public ?int $managingWarehouseId,
        public ?int $roomId,
        public ?int $holderDepartmentId,
        public \DateTimeInterface $operationDate,
        /**
         * Карточка устройства (Ф4). В остаток НЕ входит — на ключ строки остатка не влияет.
         * У перемещения это перемещаемая карточка, у расхода — устройство-потребитель
         * (картридж В принтер). Размещение устройства вычисляется как последнее
         * приходящее движение с этим device_id.
         */
        public ?int $deviceId = null,
    ) {
    }

    /** Ключ строки остатка для детерминированной сортировки блокировок. */
    public function stockKey(): string
    {
        return sprintf(
            '%010d-%010d-%010d-%010d-%010d',
            $this->nomenclatureId,
            $this->holderUserId ?? 0,
            $this->holderWarehouseId ?? 0,
            $this->managingWarehouseId ?? 0,
            $this->roomId ?? 0,
        );
    }

    public static function fromMovementInverted(Movement $movement): self
    {
        return new self(
            nomenclatureId: (int) $movement->getNomenclature()->getId(),
            direction: -$movement->getDirection(),
            quantity: (string) $movement->getQuantity(),
            holderUserId: $movement->getHolderUser()?->getId(),
            holderWarehouseId: $movement->getHolderWarehouse()?->getId(),
            managingWarehouseId: $movement->getManagingWarehouse()?->getId(),
            roomId: $movement->getRoom()?->getId(),
            holderDepartmentId: $movement->getHolderDepartmentId(),
            operationDate: new \DateTimeImmutable(),
            // Карточку сторно тоже двигает: иначе после отмены перемещения устройство
            // осталось бы числиться у прежнего держателя.
            deviceId: $movement->getDevice()?->getId(),
        );
    }
}
