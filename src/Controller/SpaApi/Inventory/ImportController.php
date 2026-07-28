<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Category;
use App\Entity\Inventory\ImportBatch;
use App\Entity\Inventory\ImportRow;
use App\Entity\User\User;
use App\Enum\Inventory\ImportFormat;
use App\Enum\Inventory\ImportRowStatus;
use App\Repository\Inventory\ImportBatchRepository;
use App\Repository\Inventory\ImportRowRepository;
use App\Repository\Inventory\NomenclatureRepository;
use App\Repository\Inventory\WarehouseRepository;
use App\Service\SpaApi\Inventory\Import\XlsxImportService;
use App\Service\SpaApi\Inventory\InventoryApiPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Импорт выгрузок 1С. Только администратор инвентаризации: импорт заводит остатки,
 * то есть создаёт материальную ответственность людей.
 */
#[Route('/spa/api/inventory/import')]
#[IsGranted('ROLE_INVENTORY_ADMIN')]
final class ImportController extends AbstractController
{
    private const MAX_FILE_SIZE = 20 * 1024 * 1024;

    /**
     * XLSX разрешён только здесь и в сверке. В документообороте он запрещён намеренно:
     * приложением к документу должен быть скан, а не редактируемая таблица.
     */
    private const ALLOWED_MIME = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/zip', // xlsx — это zip; часть окружений определяет его так
    ];

    private const ROWS_PAGE_SIZE = 50;

    public function __construct(
        private readonly XlsxImportService $import,
        private readonly ImportBatchRepository $batches,
        private readonly ImportRowRepository $rows,
        private readonly WarehouseRepository $warehouses,
        private readonly NomenclatureRepository $nomenclatures,
        private readonly InventoryApiPresenter $presenter,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Реестр загрузок.
     *
     * Нужен, чтобы отказ по дублю файла (`inventory_import_duplicate_file`) не был тупиком:
     * оператор говорит «этот файл уже загружали», а открыть прежнюю загрузку было негде.
     */
    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->query->get('page_size', 20)));

        $total = $this->batches->count([]);
        $items = $this->batches->findBy([], ['id' => 'DESC'], $pageSize, ($page - 1) * $pageSize);

        return $this->json([
            'batches' => array_map($this->presenter->importBatch(...), $items),
            'pagination' => $this->presenter->pagination($page, $pageSize, $total),
        ]);
    }

    /**
     * Загрузка и разбор файла. Документ не создаётся — только staging строк,
     * чтобы человек сначала увидел, что распозналось.
     */
    #[Route('', methods: ['POST'])]
    public function upload(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $uploaded = $request->files->get('file');
        if ($uploaded === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_FILE_REQUIRED], Response::HTTP_BAD_REQUEST);
        }
        if ($uploaded->getSize() > self::MAX_FILE_SIZE) {
            return $this->json(['error' => SpaApiError::INVENTORY_FILE_TOO_LARGE], Response::HTTP_BAD_REQUEST);
        }

        // MIME у xlsx определяется по-разному, поэтому расширение — равноправный признак.
        $extension = mb_strtolower((string) $uploaded->getClientOriginalExtension());
        $mime = (string) $uploaded->getMimeType();
        if (!\in_array($mime, self::ALLOWED_MIME, true) && !\in_array($extension, ['xlsx', 'xls'], true)) {
            return $this->json(['error' => SpaApiError::INVENTORY_FILE_INVALID_TYPE], Response::HTTP_BAD_REQUEST);
        }

        $format = ImportFormat::tryFrom((string) $request->request->get('format', ''));
        if ($format === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_IMPORT_INVALID_FORMAT], Response::HTTP_BAD_REQUEST);
        }

        // Для employee_sheet это склад-контур МОЛа, для stock_sheet — склад-приёмник.
        // Остаток на сотруднике без контура запрещён, поэтому склад обязателен в обоих случаях.
        $warehouse = $this->warehouses->find((int) $request->request->get('warehouse_id', 0));
        if ($warehouse === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }

        $autoCategoryId = (int) $request->request->get('auto_category_id', 0);
        $autoCategory = $autoCategoryId > 0 ? $this->em->find(Category::class, $autoCategoryId) : null;

        $batch = $this->import->stage(
            $uploaded->getPathname(),
            (string) $uploaded->getClientOriginalName(),
            $format,
            $warehouse,
            $autoCategory,
            $user,
        );

        return $this->json(['batch' => $this->presenter->importBatch($batch)], Response::HTTP_CREATED);
    }

    /**
     * Батч со строками. Фильтр по статусу нужен оператору, чтобы сразу открыть проблемные.
     */
    #[Route('/{batchId}', requirements: ['batchId' => '\d+'], methods: ['GET'])]
    public function view(int $batchId, Request $request): JsonResponse
    {
        $batch = $this->findBatch($batchId);

        $statusFilter = ImportRowStatus::tryFrom((string) $request->query->get('status', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->query->get('page_size', self::ROWS_PAGE_SIZE)));

        // Фильтр и срез — в SQL, а не после выборки: батч владельца это 2184 строки,
        // и поднимать их все ради пятидесяти на экране незачем.
        $result = $this->rows->findPageForBatch($batch, $statusFilter, $page, $pageSize);

        return $this->json([
            'batch' => $this->presenter->importBatch($batch),
            'rows' => array_map($this->presenter->importRow(...), $result['rows']),
            'pagination' => $this->presenter->pagination($page, $pageSize, $result['total']),
        ]);
    }

    /**
     * Ручной маппинг проблемной строки. Сопоставление запоминается в alias-таблицу,
     * поэтому следующая выгрузка с тем же написанием распознается сама.
     */
    #[Route('/{batchId}/rows/{rowId}', requirements: ['batchId' => '\d+', 'rowId' => '\d+'], methods: ['PATCH'])]
    public function remapRow(int $batchId, int $rowId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $batch = $this->findBatch($batchId);

        $row = $this->em->find(ImportRow::class, $rowId);
        if ($row === null || $row->getBatch()?->getId() !== $batch->getId()) {
            return $this->json(['error' => SpaApiError::INVENTORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $request->getContent(), true) ?? [];
        if (!\is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }

        $nomenclature = null;
        if (isset($payload['nomenclature_id'])) {
            $nomenclature = $this->nomenclatures->find((int) $payload['nomenclature_id']);
            if ($nomenclature === null) {
                return $this->json(['error' => SpaApiError::INVENTORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
            }
        }

        $matchedUser = null;
        if (isset($payload['user_id'])) {
            $matchedUser = $this->em->find(User::class, (int) $payload['user_id']);
            if ($matchedUser === null) {
                return $this->json(['error' => SpaApiError::INVENTORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
            }
        }

        $this->import->remapRow($row, $nomenclature, $matchedUser, $user);

        return $this->json([
            'row' => $this->presenter->importRow($row),
            'batch' => $this->presenter->importBatch($batch),
        ]);
    }

    /**
     * Сборка черновика «Ввод остатков» из готовых строк.
     *
     * Повторный вызов создаёт НОВЫЙ черновик из строк, доматченных после прошлого apply:
     * дописать в прежний нельзя, он мог быть уже проведён и заморожен.
     */
    #[Route('/{batchId}/apply', requirements: ['batchId' => '\d+'], methods: ['POST'])]
    public function apply(int $batchId, #[CurrentUser] User $user): JsonResponse
    {
        $batch = $this->findBatch($batchId);
        $document = $this->import->apply($batch, $user);

        return $this->json([
            'document' => $this->presenter->document($document, withLines: true),
            'batch' => $this->presenter->importBatch($batch),
        ], Response::HTTP_CREATED);
    }

    private function findBatch(int $batchId): ImportBatch
    {
        $batch = $this->batches->find($batchId);
        if ($batch === null) {
            throw $this->createNotFoundException(SpaApiError::INVENTORY_NOT_FOUND);
        }

        return $batch;
    }
}
