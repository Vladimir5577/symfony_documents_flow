<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Room;
use App\Repository\Inventory\RoomRepository;
use App\Service\SpaApi\Inventory\InventoryAccessService;
use App\Service\SpaApi\Inventory\InventoryApiPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spa/api/inventory/rooms')]
final class RoomController extends AbstractController
{
    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly EntityManagerInterface $em,
        private readonly InventoryAccessService $access,
        private readonly InventoryApiPresenter $presenter,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (!$this->access->canViewDepartment()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->query->get('page_size', 10)));
        $search = trim((string) $request->query->get('search', ''));

        $result = $this->rooms->findPaginated($page, $pageSize, $search);

        return $this->json([
            'rooms' => array_map($this->presenter->room(...), $result['rooms']),
            'pagination' => $this->presenter->pagination($result['page'], $result['limit'], $result['total']),
        ]);
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => SpaApiError::INVENTORY_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $room = new Room();
        $room->setName($name);
        $room->setBuilding($this->nullableString($payload['building'] ?? null));
        $room->setFloor($this->nullableString($payload['floor'] ?? null));
        $room->setNote($this->nullableString($payload['note'] ?? null));

        $this->em->persist($room);
        $this->em->flush();

        return $this->json(['room' => $this->presenter->room($room)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PATCH'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $room = $this->rooms->find($id);
        if ($room === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }

        if (\array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                return $this->json(['error' => SpaApiError::INVENTORY_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
            }
            $room->setName($name);
        }
        foreach (['building' => 'setBuilding', 'floor' => 'setFloor', 'note' => 'setNote'] as $key => $setter) {
            if (\array_key_exists($key, $payload)) {
                $room->{$setter}($this->nullableString($payload[$key]));
            }
        }
        if (\array_key_exists('is_active', $payload)) {
            $room->setIsActive((bool) $payload['is_active']);
        }

        $this->em->flush();

        return $this->json(['room' => $this->presenter->room($room)]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $room = $this->rooms->find($id);
        if ($room === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        // Gedmo SoftDeleteable: remove → deleted_at
        $this->em->remove($room);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
