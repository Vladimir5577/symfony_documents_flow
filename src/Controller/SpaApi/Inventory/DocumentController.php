<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Document;
use App\Entity\User\User;
use App\Enum\Inventory\DocumentType;
use App\Repository\Inventory\DocumentRepository;
use App\Service\SpaApi\Inventory\DocumentDraftService;
use App\Service\SpaApi\Inventory\InventoryAccessService;
use App\Service\SpaApi\Inventory\InventoryApiPresenter;
use App\Service\SpaApi\Inventory\StockPostingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spa/api/inventory/documents')]
final class DocumentController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentDraftService $drafts,
        private readonly StockPostingService $posting,
        private readonly InventoryAccessService $access,
        private readonly InventoryApiPresenter $presenter,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        // Реестр документов — только ADMIN/MOL/VIEWER (DEPT_VIEWER документы не видит — матрица;
        // сканы своего отдела он получает точечно через download)
        if (!$this->access->canViewAll()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->query->get('page_size', 10)));

        $result = $this->documents->findPaginated(
            $page,
            $pageSize,
            $request->query->get('type'),
            $request->query->get('status'),
            $this->parseDate((string) $request->query->get('date_from', '')),
            $this->parseDate((string) $request->query->get('date_to', '')),
            (string) $request->query->get('search', ''),
        );

        return $this->json([
            'documents' => array_map(fn (Document $d): array => $this->presenter->document($d), $result['documents']),
            'pagination' => $this->presenter->pagination($result['page'], $result['limit'], $result['total']),
        ]);
    }

    #[Route('/{id}', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function view(int $id): JsonResponse
    {
        if (!$this->access->canViewAll()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['document' => $this->presenter->document($this->findDocument($id), withLines: true)]);
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_INVENTORY_MOL')]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }

        // Ввод начальных остатков — только админ
        if (($payload['type'] ?? null) === DocumentType::INITIAL_BALANCE->value && !$this->access->isAdmin()) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        // Скоуп проверяется сервисом ДО persist — при отказе в БД ничего не попадает (ревью 2026-07-28)
        $document = $this->drafts->create(
            $payload,
            $user,
            fn (Document $d): bool => $this->access->canManageDocument($d, $user),
        );

        return $this->json(['document' => $this->presenter->document($document, withLines: true)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    #[IsGranted('ROLE_INVENTORY_MOL')]
    public function update(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $document = $this->findDocument($id);
        if (!$this->access->canManageDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }

        // Скоуп-отказ откатывает транзакцию правки целиком (ревью 2026-07-28)
        $document = $this->drafts->update(
            $document,
            $payload,
            $user,
            fn (Document $d): bool => $this->access->canManageDocument($d, $user),
        );

        return $this->json(['document' => $this->presenter->document($document, withLines: true)]);
    }

    #[Route('/{id}', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[IsGranted('ROLE_INVENTORY_MOL')]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $document = $this->findDocument($id);
        if (!$this->access->canManageDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        $this->drafts->softDelete($document, $user);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/post', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_INVENTORY_MOL')]
    public function post(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $document = $this->findDocument($id);
        if (!$this->access->canManageDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        $warnings = $this->posting->post($document, $user, $this->access->molWarehouseIds($user));

        // `warnings` — добавочный ключ конверта, а не ошибка: документ уже проведён.
        // Ключ отдаём всегда, чтобы клиенту не приходилось различать «нет замечаний»
        // и «сервер старой версии».
        return $this->json([
            'document' => $this->presenter->document($document, withLines: true),
            'warnings' => $warnings,
        ]);
    }

    #[Route('/{id}/reverse', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_INVENTORY_MOL')]
    public function reverse(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $document = $this->findDocument($id);
        if (!$this->access->canManageDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        $reversal = $this->posting->reverse($document, $user, $this->access->molWarehouseIds($user));

        return $this->json([
            'document' => $this->presenter->document($document),
            'reversal' => $this->presenter->document($reversal, withLines: true),
        ], Response::HTTP_CREATED);
    }

    private function findDocument(int $id): Document
    {
        $document = $this->documents->find($id);
        if ($document === null) {
            throw $this->createNotFoundException(SpaApiError::INVENTORY_NOT_FOUND);
        }

        return $document;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', trim($value));

        return $date === false ? null : $date->setTime(0, 0);
    }
}
