<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Document;
use App\Entity\Inventory\DocumentLine;
use App\Entity\User\User;
use App\Enum\Inventory\DocumentStatus;
use App\Enum\Inventory\DocumentType;
use App\Repository\Inventory\BasisTypeRepository;
use App\Repository\Inventory\NomenclatureRepository;
use App\Repository\Inventory\RoomRepository;
use App\Repository\Inventory\WarehouseRepository;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Создание и правка черновиков документов из JSON-пейлоада SPA.
 * Проведение/сторно — StockPostingService; после posted документ заморожен.
 *
 * По итогам состязательного ревью 2026-07-28:
 *  - скоуп-проверка ($scopeCheck) выполняется ДО фиксации: create — до persist,
 *    update — внутри транзакции с откатом при отказе (403 не оставляет чужих строк в БД);
 *  - update/softDelete берут PESSIMISTIC_WRITE на документ и перепроверяют DRAFT после
 *    блокировки — гонка с параллельным проведением (заморозка TOCTOU) закрыта.
 */
final class DocumentDraftService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NomenclatureRepository $nomenclatures,
        private readonly WarehouseRepository $warehouses,
        private readonly RoomRepository $rooms,
        private readonly BasisTypeRepository $basisTypes,
        private readonly UserRepository $users,
        private readonly InventoryHistoryService $history,
    ) {
    }

    /**
     * @param array<string, mixed>              $payload
     * @param callable(Document): bool          $scopeCheck проверка контура актора; вызывается ДО persist
     */
    public function create(array $payload, User $actor, callable $scopeCheck): Document
    {
        $type = DocumentType::tryFrom((string) ($payload['type'] ?? ''));
        if ($type === null || $type === DocumentType::REVERSAL) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_INVALID_PAYLOAD);
        }

        $document = new Document();
        $document->setType($type);
        $document->setStatus(DocumentStatus::DRAFT);
        $document->setNumber($this->draftNumber());
        $document->setCreatedBy($actor);
        $document->setDocDate($this->parseDate($payload['doc_date'] ?? null) ?? new \DateTimeImmutable());

        $this->applyScalarFields($document, $payload);
        $this->replaceLines($document, \is_array($payload['lines'] ?? null) ? $payload['lines'] : []);

        // Скоуп проверяется на объекте В ПАМЯТИ — при отказе в БД ничего не попадает
        if (!$scopeCheck($document)) {
            throw new AccessDeniedHttpException(SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE);
        }

        $this->em->persist($document);
        $this->em->flush();

        $this->history->log('document', (int) $document->getId(), 'create', $actor, [
            'type' => $type->value,
        ]);
        $this->em->flush();

        return $document;
    }

    /**
     * Правка черновика: транзакция + PESSIMISTIC_WRITE + перепроверка DRAFT; отказ скоупа
     * откатывает транзакцию целиком — «flush до проверки» невозможен.
     *
     * @param array<string, mixed>     $payload
     * @param callable(Document): bool $scopeCheck
     */
    public function update(Document $document, array $payload, User $actor, callable $scopeCheck): Document
    {
        $this->em->wrapInTransaction(function () use ($document, $payload, $actor, $scopeCheck): void {
            $this->em->lock($document, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($document);

            if ($document->getStatus() !== DocumentStatus::DRAFT) {
                throw new ConflictHttpException(SpaApiError::INVENTORY_DOCUMENT_FROZEN);
            }

            if (\array_key_exists('doc_date', $payload)) {
                $document->setDocDate($this->parseDate($payload['doc_date']) ?? $document->getDocDate());
            }
            $this->applyScalarFields($document, $payload);
            if (\array_key_exists('lines', $payload)) {
                $this->replaceLines($document, \is_array($payload['lines']) ? $payload['lines'] : []);
            }

            if (!$scopeCheck($document)) {
                throw new AccessDeniedHttpException(SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE); // rollback
            }

            $this->history->log('document', (int) $document->getId(), 'update', $actor, null);
            $this->em->flush();
        });

        return $document;
    }

    public function softDelete(Document $document, User $actor): void
    {
        $this->em->wrapInTransaction(function () use ($document, $actor): void {
            $this->em->lock($document, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($document);

            if ($document->getStatus() !== DocumentStatus::DRAFT) {
                throw new ConflictHttpException(SpaApiError::INVENTORY_DOCUMENT_FROZEN);
            }

            $this->history->log('document', (int) $document->getId(), 'delete', $actor, null);
            $this->em->remove($document); // Gedmo SoftDeleteable
            $this->em->flush();
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyScalarFields(Document $document, array $payload): void
    {
        foreach ([
            'external_number' => 'setExternalNumber',
            'supplier_name' => 'setSupplierName',
            'supplier_inn' => 'setSupplierInn',
            'reason' => 'setReason',
            'comment' => 'setComment',
        ] as $key => $setter) {
            if (\array_key_exists($key, $payload)) {
                $value = trim((string) ($payload[$key] ?? ''));
                $document->{$setter}($value === '' ? null : $value);
            }
        }

        if (\array_key_exists('external_date', $payload)) {
            $document->setExternalDate($this->parseDate($payload['external_date']));
        }
        if (\array_key_exists('basis_type_id', $payload)) {
            $document->setBasisType(
                $payload['basis_type_id'] === null ? null : $this->basisTypes->find((int) $payload['basis_type_id']),
            );
        }
        if (\array_key_exists('default_managing_warehouse_id', $payload)) {
            $document->setDefaultManagingWarehouse(
                $payload['default_managing_warehouse_id'] === null
                    ? null
                    : $this->warehouses->find((int) $payload['default_managing_warehouse_id']),
            );
        }
    }

    /**
     * Полная замена строк черновика (детальные правки строк по id — избыточны для Ф1/Ф2).
     *
     * @param list<array<string, mixed>> $lines
     */
    private function replaceLines(Document $document, array $lines): void
    {
        foreach ($document->getLines()->toArray() as $existing) {
            $document->getLines()->removeElement($existing);
            $this->em->remove($existing);
        }

        foreach ($lines as $lineData) {
            if (!\is_array($lineData)) {
                throw new BadRequestHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_LINES);
            }

            $nomenclature = $this->nomenclatures->find((int) ($lineData['nomenclature_id'] ?? 0));
            $quantity = (string) ($lineData['quantity'] ?? '');
            if ($nomenclature === null || !is_numeric($quantity) || (float) $quantity <= 0) {
                throw new BadRequestHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_LINES);
            }

            $line = new DocumentLine();
            $line->setDocument($document);
            $line->setNomenclature($nomenclature);
            $line->setQuantity($quantity);
            if (isset($lineData['price']) && is_numeric((string) $lineData['price'])) {
                $line->setPrice((string) $lineData['price']);
            }

            $line->setFromHolderUser($this->refUser($lineData['from_holder_user_id'] ?? null));
            $line->setFromHolderWarehouse($this->refWarehouse($lineData['from_holder_warehouse_id'] ?? null));
            $line->setFromRoom($this->refRoom($lineData['from_room_id'] ?? null));
            $line->setFromManagingWarehouse($this->refWarehouse($lineData['from_managing_warehouse_id'] ?? null));
            $line->setToHolderUser($this->refUser($lineData['to_holder_user_id'] ?? null));
            $line->setToHolderWarehouse($this->refWarehouse($lineData['to_holder_warehouse_id'] ?? null));
            $line->setToRoom($this->refRoom($lineData['to_room_id'] ?? null));
            $line->setToManagingWarehouse($this->refWarehouse($lineData['to_managing_warehouse_id'] ?? null));

            $note = trim((string) ($lineData['note'] ?? ''));
            if ($note !== '') {
                $line->setNote($note);
            }

            $document->addLine($line);
            $this->em->persist($line);
        }
    }

    private function refUser(mixed $id): ?User
    {
        if ($id === null || $id === '' || (int) $id <= 0) {
            return null;
        }
        $user = $this->users->find((int) $id);
        if ($user === null) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_LINES);
        }

        return $user;
    }

    private function refWarehouse(mixed $id): ?\App\Entity\Inventory\Warehouse
    {
        if ($id === null || $id === '' || (int) $id <= 0) {
            return null;
        }
        $warehouse = $this->warehouses->find((int) $id);
        if ($warehouse === null) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_LINES);
        }

        return $warehouse;
    }

    private function refRoom(mixed $id): ?\App\Entity\Inventory\Room
    {
        if ($id === null || $id === '' || (int) $id <= 0) {
            return null;
        }
        $room = $this->rooms->find((int) $id);
        if ($room === null) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_LINES);
        }

        return $room;
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', trim($value));

        return $date === false ? null : $date->setTime(0, 0);
    }

    /**
     * Черновик получает временный уникальный номер; настоящий номер выдаёт счётчик при проведении.
     */
    private function draftNumber(): string
    {
        return Document::DRAFT_NUMBER_PREFIX . bin2hex(random_bytes(6));
    }
}
