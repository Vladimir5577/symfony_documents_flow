<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Device;
use App\Entity\User\User;
use App\Enum\Inventory\DeviceStatus;
use App\Repository\Inventory\DeviceRepository;
use App\Repository\Inventory\MovementRepository;
use App\Repository\Inventory\NomenclatureRepository;
use App\Service\SpaApi\Inventory\Device\DeviceLocationService;
use App\Service\SpaApi\Inventory\InventoryAccessService;
use App\Service\SpaApi\Inventory\InventoryApiPresenter;
use App\Service\SpaApi\Inventory\InventoryHistoryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Карточки-паспорта устройств.
 *
 * ⚠ Класс-гейта по роли здесь быть не должно: инвентарные роли НЕ наследуют друг друга
 * (`security.yaml`: `ROLE_INVENTORY_MOL: ROLE_USER`), поэтому `IsGranted` на классе отрезал бы
 * МОЛа. Доступ решает InventoryAccessService + DeviceLocationService в каждом методе.
 */
#[Route('/spa/api/inventory/devices')]
final class DeviceController extends AbstractController
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly NomenclatureRepository $nomenclatures,
        private readonly MovementRepository $movements,
        private readonly DeviceLocationService $location,
        private readonly InventoryAccessService $access,
        private readonly InventoryApiPresenter $presenter,
        private readonly InventoryHistoryService $history,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->access->canViewDepartment()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->query->get('page_size', 10)));
        $search = trim((string) $request->query->get('search', ''));
        $status = (string) $request->query->get('status', '');
        $nomenclatureId = $request->query->has('nomenclature_id')
            ? (int) $request->query->get('nomenclature_id')
            : null;

        // Начальник отдела видит только устройства своего подразделения; для остальных
        // ролей ограничения нет (null). Пустой список означает «видно ничего».
        $result = $this->devices->findPaginated(
            $page,
            $pageSize,
            $search,
            $status,
            $nomenclatureId,
            $this->location->visibleDeviceIds($user),
        );

        return $this->json([
            'devices' => array_map(
                fn (Device $device): array => $this->presenter->device(
                    $device,
                    $this->location->canSeeNetwork($device, $user),
                    $this->location->resolve($device),
                ),
                $result['devices'],
            ),
            'pagination' => $this->presenter->pagination($result['page'], $result['limit'], $result['total']),
        ]);
    }

    #[Route('/{id}', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function view(int $id, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->access->canViewDepartment()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $device = $this->findDevice($id);
        if (!$this->location->canView($device, $user)) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'device' => $this->presenter->device(
                $device,
                $this->location->canSeeNetwork($device, $user),
                $this->location->resolve($device),
            ),
        ]);
    }

    /**
     * Создаёт карточку только администратор: у нового устройства ещё нет движений,
     * а значит нет и контура, по которому можно было бы ограничить МОЛа.
     */
    #[Route('', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->access->isAdmin()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => SpaApiError::INVENTORY_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $nomenclature = $this->nomenclatures->find((int) ($payload['nomenclature_id'] ?? 0));
        if ($nomenclature === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }

        $device = new Device();
        $device->setName($name);
        $device->setNomenclature($nomenclature);
        $this->applyEditableFields($device, $payload);

        $this->em->persist($device);
        $this->em->flush();

        $this->history->log('device', (int) $device->getId(), 'create', $user, ['name' => $name]);
        $this->em->flush();

        return $this->json([
            'device' => $this->presenter->device($device, withNetwork: true, location: $this->location->resolve($device)),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $device = $this->findDevice($id);
        if (!$this->location->canEdit($device, $user)) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }

        if (\array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                return $this->json(['error' => SpaApiError::INVENTORY_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
            }
            $device->setName($name);
        }
        // Номенклатуру карточки меняет только администратор: от неё зависит, какой строкой
        // документа устройство разрешено двигать (проверка в StockPostingService).
        if (\array_key_exists('nomenclature_id', $payload) && $this->access->isAdmin()) {
            $nomenclature = $this->nomenclatures->find((int) $payload['nomenclature_id']);
            if ($nomenclature === null) {
                return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
            }
            $device->setNomenclature($nomenclature);
        }

        $this->applyEditableFields($device, $payload);
        $this->em->flush();

        $this->history->log('device', (int) $device->getId(), 'update', $user, [
            'fields' => array_keys($payload),
        ]);
        $this->em->flush();

        return $this->json([
            'device' => $this->presenter->device(
                $device,
                $this->location->canSeeNetwork($device, $user),
                $this->location->resolve($device),
            ),
        ]);
    }

    /**
     * Мягкое удаление карточки. Движения ссылаются на устройство с RESTRICT, поэтому
     * строка остаётся в БД, а история движений не рвётся.
     */
    #[Route('/{id}', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->access->isAdmin()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $device = $this->findDevice($id);

        $this->history->log('device', (int) $device->getId(), 'delete', $user, [
            'name' => $device->getName(),
        ]);
        $this->em->remove($device); // soft delete (Gedmo)
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Движения карточки — «где устройство было» во времени.
     */
    #[Route('/{id}/movements', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function movements(int $id, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->access->canViewDepartment()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $device = $this->findDevice($id);
        if (!$this->location->canView($device, $user)) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        $movements = $this->movements->findByDevice($device);

        return $this->json([
            'movements' => array_map($this->presenter->movement(...), $movements),
        ]);
    }

    /**
     * Расход материалов В это устройство (картриджи, комплектующие).
     *
     * Это подмножество движений: только расходные (-1) строки документов `consumption`,
     * привязанных к карточке. Именно они отвечают на вопрос «сколько картриджей съел принтер».
     */
    #[Route('/{id}/consumption', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function consumption(int $id, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->access->canViewDepartment()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $device = $this->findDevice($id);
        if (!$this->location->canView($device, $user)) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        $rows = array_values(array_filter(
            $this->movements->findByDevice($device),
            static fn ($movement): bool => $movement->getDirection() === -1
                && $movement->getDocument()?->getType() === \App\Enum\Inventory\DocumentType::CONSUMPTION,
        ));

        // Итог по позициям: оператору нужнее «картриджей — 7», чем список из семи строк.
        // Суммируем в целых тысячных (масштаб схемы DECIMAL(14,3)) — float здесь запрещён
        // конвенцией репозитория и дал бы погрешность на дробных количествах.
        $totals = [];
        foreach ($rows as $movement) {
            $nomenclature = $movement->getNomenclature();
            $key = (int) $nomenclature->getId();
            if (!isset($totals[$key])) {
                $totals[$key] = [
                    'nomenclature' => $this->presenter->nomenclature($nomenclature),
                    'thousandths' => 0,
                ];
            }
            $totals[$key]['thousandths'] += (int) round((float) (string) $movement->getQuantity() * 1000);
        }

        return $this->json([
            'consumption' => array_map($this->presenter->movement(...), $rows),
            'totals' => array_values(array_map(
                static fn (array $total): array => [
                    'nomenclature' => $total['nomenclature'],
                    'quantity' => number_format($total['thousandths'] / 1000, 3, '.', ''),
                ],
                $totals,
            )),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyEditableFields(Device $device, array $payload): void
    {
        if (\array_key_exists('inventory_number', $payload)) {
            $device->setInventoryNumber($this->nullableString($payload['inventory_number']));
        }
        if (\array_key_exists('serial_number', $payload)) {
            $device->setSerialNumber($this->nullableString($payload['serial_number']));
        }
        if (\array_key_exists('ip_address', $payload)) {
            $device->setIpAddress($this->nullableString($payload['ip_address']));
        }
        if (\array_key_exists('mac_address', $payload)) {
            $device->setMacAddress($this->nullableString($payload['mac_address']));
        }
        if (\array_key_exists('hostname', $payload)) {
            $device->setHostname($this->nullableString($payload['hostname']));
        }
        if (\array_key_exists('status', $payload)) {
            $status = DeviceStatus::tryFrom((string) $payload['status']);
            if ($status !== null) {
                $device->setStatus($status);
            }
        }
        if (\array_key_exists('manual_location', $payload)) {
            $device->setManualLocation($this->nullableString($payload['manual_location']));
        }
        if (\array_key_exists('specs', $payload)) {
            $device->setSpecs($payload['specs']);
        }
        if (\array_key_exists('note', $payload)) {
            $device->setNote($this->nullableString($payload['note']));
        }
    }

    private function findDevice(int $id): Device
    {
        $device = $this->devices->find($id);
        if ($device === null) {
            throw $this->createNotFoundException(SpaApiError::INVENTORY_NOT_FOUND);
        }

        return $device;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
