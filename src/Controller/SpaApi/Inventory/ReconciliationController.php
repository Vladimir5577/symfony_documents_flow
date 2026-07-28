<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Reconciliation;
use App\Entity\Inventory\ReconciliationLine;
use App\Entity\User\User;
use App\Enum\Inventory\ComparisonStatus;
use App\Enum\Inventory\ResolutionStatus;
use App\Enum\User\UserRole;
use App\Repository\Inventory\NomenclatureRepository;
use App\Repository\Inventory\ReconciliationLineRepository;
use App\Repository\Inventory\ReconciliationRepository;
use App\Repository\Inventory\WarehouseRepository;
use App\Service\SpaApi\Inventory\InventoryAccessService;
use App\Service\SpaApi\Inventory\InventoryApiPresenter;
use App\Service\SpaApi\Inventory\Reconciliation\ReconciliationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Сверка остатков системы с оборотно-сальдовой ведомостью 1С.
 */
/**
 * ⚠ Класс-гейта по роли здесь быть не должно. Инвентарные роли НЕ наследуют друг друга
 * (`security.yaml`: `ROLE_INVENTORY_MOL: ROLE_USER`), поэтому `IsGranted('ROLE_INVENTORY_VIEWER')`
 * на классе отбивал бы МОЛа — роль, ради которой сверка и делается. Доступ решает
 * InventoryAccessService в каждом методе, как в остальных контроллерах модуля.
 */
#[Route('/spa/api/inventory/reconciliations')]
final class ReconciliationController extends AbstractController
{
    private const MAX_FILE_SIZE = 20 * 1024 * 1024;

    /** XLSX разрешён только в импорте и здесь. */
    private const ALLOWED_MIME = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/zip',
    ];

    public function __construct(
        private readonly ReconciliationService $reconciliations,
        private readonly ReconciliationRepository $repository,
        private readonly ReconciliationLineRepository $lines,
        private readonly WarehouseRepository $warehouses,
        private readonly NomenclatureRepository $nomenclatures,
        private readonly InventoryAccessService $access,
        private readonly InventoryApiPresenter $presenter,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->access->canViewAll()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->query->get('page_size', 10)));

        $all = $this->repository->findBy([], ['id' => 'DESC']);

        $allowedWarehouseIds = $this->scopeWarehouseIds($user);
        if ($allowedWarehouseIds !== null) {
            $all = array_values(array_filter(
                $all,
                fn (Reconciliation $r): bool => $this->isWithinScope($r, $allowedWarehouseIds),
            ));
        }

        $total = \count($all);
        $slice = \array_slice($all, ($page - 1) * $pageSize, $pageSize);

        return $this->json([
            'reconciliations' => array_map($this->presenter->reconciliation(...), $slice),
            'pagination' => $this->presenter->pagination($page, $pageSize, $total),
        ]);
    }

    /**
     * Запуск сверки: разбор ОСВ, расчёт диффа, фиксация границы движений.
     * Создавать сверку может тот, кто ведёт учёт, — не просто наблюдатель.
     */
    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_INVENTORY_MOL')]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $uploaded = $request->files->get('file');
        if ($uploaded === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_FILE_REQUIRED], Response::HTTP_BAD_REQUEST);
        }
        if ($uploaded->getSize() > self::MAX_FILE_SIZE) {
            return $this->json(['error' => SpaApiError::INVENTORY_FILE_TOO_LARGE], Response::HTTP_BAD_REQUEST);
        }

        $extension = mb_strtolower((string) $uploaded->getClientOriginalExtension());
        if (!\in_array((string) $uploaded->getMimeType(), self::ALLOWED_MIME, true)
            && !\in_array($extension, ['xlsx', 'xls'], true)
        ) {
            return $this->json(['error' => SpaApiError::INVENTORY_FILE_INVALID_TYPE], Response::HTTP_BAD_REQUEST);
        }

        $asOfDate = $this->parseDate((string) $request->request->get('as_of_date', ''));
        if ($asOfDate === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }

        $warehouse = $this->warehouses->find((int) $request->request->get('warehouse_id', 0));
        if ($warehouse === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }
        $allowedWarehouseIds = $this->scopeWarehouseIds($user);
        if ($allowedWarehouseIds !== null
            && !\in_array((int) $warehouse->getId(), $allowedWarehouseIds, true)
        ) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        $reconciliation = $this->reconciliations->create($uploaded, $asOfDate, $warehouse, $user);

        return $this->json([
            'reconciliation' => $this->presenter->reconciliation($reconciliation),
        ], Response::HTTP_CREATED);
    }

    /**
     * Сверка со строками. Фильтр по статусу сравнения — основной рабочий инструмент:
     * разбирают обычно только расхождения, а не весь список.
     */
    #[Route('/{id}', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function view(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->access->canViewAll()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $reconciliation = $this->findReconciliation($id);

        $allowedWarehouseIds = $this->scopeWarehouseIds($user);
        if ($allowedWarehouseIds !== null && !$this->isWithinScope($reconciliation, $allowedWarehouseIds)) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        $comparison = ComparisonStatus::tryFrom((string) $request->query->get('comparison_status', ''));
        $resolution = ResolutionStatus::tryFrom((string) $request->query->get('resolution_status', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = max(1, min(200, (int) $request->query->get('page_size', 50)));

        // Фильтр, счётчик и срез — в SQL. Раньше все строки сверки поднимались в память
        // ради одной страницы; на выгрузке владельца это 2184 строки на каждый запрос.
        $result = $this->lines->findPageForReconciliation(
            $reconciliation,
            $comparison,
            $resolution,
            $page,
            $pageSize,
        );

        return $this->json([
            'reconciliation' => $this->presenter->reconciliation($reconciliation),
            'summary' => $this->lines->summaryForReconciliation($reconciliation),
            'lines' => array_map($this->presenter->reconciliationLine(...), $result['rows']),
            'pagination' => $this->presenter->pagination($page, $pageSize, $result['total']),
        ]);
    }

    /**
     * Решение по строке — замена ручных колонок «Выяснить» и «Примечание» из Excel владельца.
     */
    #[Route('/{id}/lines/{lineId}', requirements: ['id' => '\d+', 'lineId' => '\d+'], methods: ['PATCH'])]
    #[IsGranted('ROLE_INVENTORY_MOL')]
    public function updateLine(int $id, int $lineId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $reconciliation = $this->findReconciliation($id);

        // Та же проверка контура, что и при создании: без неё МОЛ склада A мог бы
        // помечать «объяснено» и переназначать номенклатуру в сверке склада B.
        $allowedWarehouseIds = $this->scopeWarehouseIds($user);
        if ($allowedWarehouseIds !== null && !$this->isWithinScope($reconciliation, $allowedWarehouseIds)) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        $line = $this->em->find(ReconciliationLine::class, $lineId);
        if ($line === null || $line->getReconciliation()?->getId() !== $reconciliation->getId()) {
            return $this->json(['error' => SpaApiError::INVENTORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $request->getContent(), true) ?? [];
        if (!\is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }

        $resolution = isset($payload['resolution_status'])
            ? ResolutionStatus::tryFrom((string) $payload['resolution_status'])
            : null;

        $nomenclature = null;
        if (isset($payload['nomenclature_id'])) {
            $nomenclature = $this->nomenclatures->find((int) $payload['nomenclature_id']);
            if ($nomenclature === null) {
                return $this->json(['error' => SpaApiError::INVENTORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
            }
        }

        $note = isset($payload['note']) ? (string) $payload['note'] : null;

        $this->reconciliations->updateLine($line, $resolution, $note, $nomenclature);

        return $this->json(['line' => $this->presenter->reconciliationLine($line)]);
    }

    /**
     * Склады, которыми ограничен пользователь. `null` — ограничений нет.
     *
     * Ограничен только МОЛ: он отвечает за свой контур. Админ и наблюдатели (VIEWER)
     * по матрице доступа видят сверки целиком, поэтому им список складов не нужен —
     * и подставлять им пустой список нельзя, иначе они не увидят вообще ничего.
     *
     * @return list<int>|null
     */
    private function scopeWarehouseIds(User $user): ?array
    {
        // ⚠ Ограничиваем ТОЛЬКО чистого МОЛа. Роли не наследуют друг друга, поэтому человеку
        // могут выдать МОЛ и наблюдателя разом — и тогда действовать должно более широкое
        // право, а не более узкое. Раньше такой пользователь резался как МОЛ и не видел
        // сверок чужих складов, хотя как наблюдатель обязан видеть все (находка ревью Codex).
        if (!$this->access->isMol()
            || $this->access->isAdmin()
            || $this->security->isGranted(UserRole::ROLE_INVENTORY_VIEWER->value)
        ) {
            return null;
        }

        return $this->access->molWarehouseIds($user) ?? [];
    }

    /**
     * @param list<int> $allowedWarehouseIds
     */
    private function isWithinScope(Reconciliation $reconciliation, array $allowedWarehouseIds): bool
    {
        $warehouseId = $reconciliation->getWarehouse()?->getId();

        return $warehouseId !== null && \in_array((int) $warehouseId, $allowedWarehouseIds, true);
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($trimmed);
        } catch (\Exception) {
            return null;
        }
    }

    private function findReconciliation(int $id): Reconciliation
    {
        $reconciliation = $this->repository->find($id);
        if ($reconciliation === null) {
            throw $this->createNotFoundException(SpaApiError::INVENTORY_NOT_FOUND);
        }

        return $reconciliation;
    }
}
