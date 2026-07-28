<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\BasisType;
use App\Repository\Inventory\BasisTypeRepository;
use App\Service\SpaApi\Inventory\InventoryAccessService;
use App\Service\SpaApi\Inventory\InventoryApiPresenter;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spa/api/inventory/basis-types')]
final class BasisTypeController extends AbstractController
{
    public function __construct(
        private readonly BasisTypeRepository $basisTypes,
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

        $result = $this->basisTypes->findPaginated($page, $pageSize, $search);

        return $this->json([
            'basis_types' => array_map($this->presenter->basisType(...), $result['basis_types']),
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

        $basisType = new BasisType();
        $basisType->setName($name);
        $basisType->setSort((int) ($payload['sort'] ?? 0));

        $this->em->persist($basisType);
        $this->em->flush();

        return $this->json(['basis_type' => $this->presenter->basisType($basisType)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PATCH'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $basisType = $this->basisTypes->find($id);
        if ($basisType === null) {
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
            $basisType->setName($name);
        }
        if (\array_key_exists('sort', $payload)) {
            $basisType->setSort((int) $payload['sort']);
        }
        if (\array_key_exists('is_active', $payload)) {
            $basisType->setIsActive((bool) $payload['is_active']);
        }

        $this->em->flush();

        return $this->json(['basis_type' => $this->presenter->basisType($basisType)]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $basisType = $this->basisTypes->find($id);
        if ($basisType === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->em->remove($basisType);
            $this->em->flush();
        } catch (ForeignKeyConstraintViolationException) {
            return $this->json(['error' => SpaApiError::INVENTORY_IN_USE], Response::HTTP_CONFLICT);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
