<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Device;
use App\Entity\Inventory\DeviceRepair;
use App\Entity\User\User;
use App\Repository\Inventory\DeviceRepairRepository;
use App\Repository\Inventory\DeviceRepository;
use App\Service\SpaApi\Inventory\Device\DeviceLocationService;
use App\Service\SpaApi\Inventory\InventoryAccessService;
use App\Service\SpaApi\Inventory\InventoryApiPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Ремонты устройства. Читают все, кто видит карточку; заводит записи тот, кто вправе
 * карточку править (администратор или МОЛ своего контура) — ремонт это факт эксплуатации,
 * а не бухгалтерская операция, отдельного документа он не требует.
 *
 * Класс-гейта по роли нет намеренно: инвентарные роли не наследуют друг друга.
 */
#[Route('/spa/api/inventory')]
final class DeviceRepairController extends AbstractController
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly DeviceRepairRepository $repairs,
        private readonly DeviceLocationService $location,
        private readonly InventoryAccessService $access,
        private readonly InventoryApiPresenter $presenter,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/devices/{id}/repairs', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function list(int $id, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->access->canViewDepartment()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $device = $this->findDevice($id);
        // canViewDepartment() отвечает «пустят ли в раздел», а не «видно ли это устройство»:
        // без объектной проверки начальник отдела читал ремонты любого устройства.
        if (!$this->location->canView($device, $user)) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        $rows = $this->repairs->findBy(['device' => $device], ['repairDate' => 'DESC', 'id' => 'DESC']);

        return $this->json([
            'repairs' => array_map($this->presenter->repair(...), $rows),
        ]);
    }

    #[Route('/devices/{id}/repairs', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function create(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $device = $this->findDevice($id);
        if (!$this->location->canEdit($device, $user)) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }

        $description = trim((string) ($payload['description'] ?? ''));
        $repairDate = $this->parseDate($payload['repair_date'] ?? null);
        if ($description === '' || $repairDate === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }

        $repair = new DeviceRepair();
        $repair->setDevice($device);
        $repair->setDescription($description);
        $repair->setRepairDate($repairDate);
        $repair->setCost($this->nullableString($payload['cost'] ?? null));
        $repair->setPerformedBy($this->nullableString($payload['performed_by'] ?? null));
        $repair->setCreatedBy($user);

        $this->em->persist($repair);
        $this->em->flush();

        return $this->json(['repair' => $this->presenter->repair($repair)], Response::HTTP_CREATED);
    }

    #[Route('/repairs/{rid}', requirements: ['rid' => '\d+'], methods: ['PATCH'])]
    public function update(int $rid, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $repair = $this->findRepair($rid);
        $device = $repair->getDevice();
        if ($device === null || !$this->location->canEdit($device, $user)) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }

        if (\array_key_exists('description', $payload)) {
            $description = trim((string) $payload['description']);
            if ($description === '') {
                return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
            }
            $repair->setDescription($description);
        }
        if (\array_key_exists('repair_date', $payload)) {
            $date = $this->parseDate($payload['repair_date']);
            if ($date === null) {
                return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
            }
            $repair->setRepairDate($date);
        }
        if (\array_key_exists('cost', $payload)) {
            $repair->setCost($this->nullableString($payload['cost']));
        }
        if (\array_key_exists('performed_by', $payload)) {
            $repair->setPerformedBy($this->nullableString($payload['performed_by']));
        }

        $this->em->flush();

        return $this->json(['repair' => $this->presenter->repair($repair)]);
    }

    #[Route('/repairs/{rid}', requirements: ['rid' => '\d+'], methods: ['DELETE'])]
    public function delete(int $rid, #[CurrentUser] User $user): JsonResponse
    {
        $repair = $this->findRepair($rid);
        $device = $repair->getDevice();
        if ($device === null || !$this->location->canEdit($device, $user)) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        $this->em->remove($repair);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function findDevice(int $id): Device
    {
        $device = $this->devices->find($id);
        if ($device === null) {
            throw $this->createNotFoundException(SpaApiError::INVENTORY_NOT_FOUND);
        }

        return $device;
    }

    private function findRepair(int $rid): DeviceRepair
    {
        $repair = $this->repairs->find($rid);
        if ($repair === null) {
            throw $this->createNotFoundException(SpaApiError::INVENTORY_NOT_FOUND);
        }

        return $repair;
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
