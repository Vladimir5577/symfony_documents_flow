<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Inventory\Device;

use App\Entity\Inventory\Device;
use App\Entity\Inventory\Movement;
use App\Entity\User\User;
use App\Repository\Inventory\MovementRepository;
use App\Service\SpaApi\Inventory\InventoryAccessService;

/**
 * Где находится устройство и кто вправе видеть его сетевые поля.
 *
 * Размещение НЕ хранится в карточке: это производная от журнала движений
 * (последнее приходящее движение с этим `device_id`). Дублировать его колонкой
 * означало бы завести второй источник истины, который разъедется с ledger'ом
 * при первом же сторно. Поле `manual_location` — честно «указано вручную»
 * и применяется только там, где движений нет вовсе.
 */
final class DeviceLocationService
{
    public function __construct(
        private readonly MovementRepository $movements,
        private readonly InventoryAccessService $access,
    ) {
    }

    /**
     * Текущее размещение карточки.
     *
     * `source`: `movement` — вычислено по журналу; `manual` — движений нет, показываем
     * ручную пометку; `unknown` — нет ни того, ни другого.
     *
     * @return array{holder: array<string, mixed>|null, room: array<string, mixed>|null, source: string, movement_id: int|null, operation_date: string|null}
     */
    public function resolve(Device $device): array
    {
        $movement = $this->lastIncoming($device);

        if ($movement === null) {
            $manual = trim((string) $device->getManualLocation());

            return [
                'holder' => null,
                'room' => null,
                'source' => $manual === '' ? 'unknown' : 'manual',
                'movement_id' => null,
                'operation_date' => null,
            ];
        }

        $holderUser = $movement->getHolderUser();
        $holderWarehouse = $movement->getHolderWarehouse();
        $room = $movement->getRoom();

        return [
            'holder' => $holderUser !== null
                ? ['type' => 'employee', 'id' => $holderUser->getId(), 'name' => $this->fullName($holderUser)]
                : ($holderWarehouse === null ? null : ['type' => 'warehouse', 'id' => $holderWarehouse->getId(), 'name' => $holderWarehouse->getName()]),
            'room' => $room === null ? null : ['id' => $room->getId(), 'name' => $room->getName()],
            'source' => 'movement',
            'movement_id' => $movement->getId(),
            'operation_date' => $movement->getOperationDate()?->format('Y-m-d'),
        ];
    }

    /**
     * Склад, отвечающий за устройство: склад-держатель либо контур сотрудника-держателя.
     * `null` — устройство ещё не двигалось, контура у него нет.
     */
    public function scopeWarehouseId(Device $device): ?int
    {
        $movement = $this->lastIncoming($device);
        if ($movement === null) {
            return null;
        }

        $warehouseId = $movement->getHolderWarehouse()?->getId()
            ?? $movement->getManagingWarehouse()?->getId();

        return $warehouseId === null ? null : (int) $warehouseId;
    }

    /**
     * Видны ли сетевые поля (IP/MAC/hostname) этому пользователю.
     *
     * По матрице доступа: администратор — всегда; МОЛ — только по устройствам своего
     * контура. Остальным ролям сеть не показывается вовсе: знание адресов и хостнеймов
     * инфраструктуры — отдельная от учёта ТМЦ ценность.
     *
     * Устройство без движений контура не имеет, поэтому МОЛ его сеть НЕ видит:
     * иначе достаточно было бы завести карточку, чтобы прочитать чужие адреса.
     */
    public function canSeeNetwork(Device $device, User $user): bool
    {
        if ($this->access->isAdmin()) {
            return true;
        }
        if (!$this->access->isMol()) {
            return false;
        }

        $scopeId = $this->scopeWarehouseId($device);
        if ($scopeId === null) {
            return false;
        }

        $own = $this->access->molWarehouseIds($user) ?? [];

        return \in_array($scopeId, $own, true);
    }

    /**
     * Может ли пользователь править карточку. По матрице: администратор и МОЛ своего контура.
     * Карточку без движений правит только администратор — она ещё ничья.
     */
    public function canEdit(Device $device, User $user): bool
    {
        return $this->canSeeNetwork($device, $user);
    }

    /**
     * Может ли пользователь ВИДЕТЬ карточку.
     *
     * Админ, МОЛ и наблюдатель видят все устройства (матрица доступа). Начальник отдела —
     * только те, что хоть раз приходили в его подразделение или потомков: у устройства нет
     * поля «подразделение», принадлежность всегда производная от движений.
     *
     * Отдельный метод, а не `canViewDepartment()`: тот отвечает «пустят ли в раздел вообще»,
     * а этот — «виден ли конкретный объект». Раньше их путали, и начальник отдела читал
     * движения, расход и ремонты ЛЮБОГО устройства предприятия (находка ревью Codex).
     */
    public function canView(Device $device, User $user): bool
    {
        if ($this->access->canViewAll()) {
            return true;
        }
        if (!$this->access->canViewDepartment()) {
            return false;
        }

        $visibleIds = $this->visibleDeviceIds($user);

        return $visibleIds === null || \in_array((int) $device->getId(), $visibleIds, true);
    }

    /**
     * ID устройств, доступных пользователю. `null` — ограничений нет (админ/МОЛ/наблюдатель).
     *
     * @return list<int>|null
     */
    public function visibleDeviceIds(User $user): ?array
    {
        if ($this->access->canViewAll()) {
            return null;
        }

        return $this->movements->findDeviceIdsInDepartments($this->access->departmentScopeIds($user));
    }

    /**
     * Последнее ПРИХОДЯЩЕЕ движение карточки — оно и определяет, где устройство сейчас.
     * Расходные (-1) для размещения не годятся: у перемещения их два, и минусовое
     * описывает, откуда предмет ушёл, а не где он оказался.
     */
    private function lastIncoming(Device $device): ?Movement
    {
        $incoming = array_values(array_filter(
            $this->movements->findByDevice($device),
            static fn (Movement $m): bool => $m->getDirection() === 1,
        ));

        return $incoming === [] ? null : $incoming[\count($incoming) - 1];
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
