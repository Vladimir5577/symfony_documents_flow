<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Item;
use App\Entity\User\User;
use App\Repository\Inventory\ItemRepository;
use App\Service\Inventory\InventoryAccessResolver;
use App\Service\Inventory\ItemFilterFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Выгрузка товаров в xlsx.
 *
 * Фильтры и скоуп — те же, что в списке: выгружается ровно то, что человек видит
 * на экране. Ответственный за категорию получит свою категорию по своему поддереву,
 * админ организации — всё поддерево, главный администратор — что запросил.
 *
 * Набор и порядок колонок здесь заодно задают формат будущего импорта: файл
 * выгружается, правится в Excel и заливается обратно. Поэтому первой колонкой
 * идёт id — по нему импорт отличит правку существующего товара от новой строки.
 */
#[Route('/spa/api/inventory/export')]
#[IsGranted('ROLE_INVENTORY_MANAGER')]
final class ExportController extends AbstractController
{
    /**
     * Потолок строк в одной выгрузке. Лист собирается в памяти целиком, поэтому
     * вместо молчаливого обрезания отдаём ошибку с просьбой сузить фильтр.
     */
    private const EXPORT_LIMIT = 10000;

    private const COLUMNS = [
        'id' => 'ID',
        'inventoryNumber' => 'Инвентарный номер',
        'name' => 'Наименование',
        'category' => 'Категория',
        'organization' => 'Организация',
        'serialNumber' => 'Серийный номер',
        'status' => 'Статус',
        'assignedTo' => 'Владелец',
        'price' => 'Цена',
        'purchasedAt' => 'Дата приобретения',
        'upd' => 'УПД',
        'description' => 'Описание',
    ];

    public function __construct(
        private readonly ItemRepository $itemRepository,
        private readonly InventoryAccessResolver $accessResolver,
        private readonly ItemFilterFactory $filterFactory,
    ) {
    }

    #[Route('/items', name: 'spa_api_inventory_export_items', methods: ['GET'])]
    public function items(Request $request): Response
    {
        $scope = $this->accessResolver->resolveCurrent();
        if ($scope->isEmpty()) {
            return $this->json(['error' => SpaApiError::INVENTORY_NO_ACCESS], Response::HTTP_FORBIDDEN);
        }

        $filters = $this->filterFactory->fromRequest($request);

        $total = $this->itemRepository->countFiltered($scope, $filters);
        if ($total > self::EXPORT_LIMIT) {
            return $this->json([
                'error' => SpaApiError::INVENTORY_EXPORT_TOO_LARGE,
                'total' => $total,
                'limit' => self::EXPORT_LIMIT,
            ], Response::HTTP_CONFLICT);
        }

        $items = $this->itemRepository->findFiltered($scope, $filters, self::EXPORT_LIMIT);

        return $this->streamXlsx($this->build($items));
    }

    /**
     * @param Item[] $items
     */
    private function build(array $items): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Инвентаризация');

        $headers = array_values(self::COLUMNS);
        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }

        $lastColumn = count($headers);
        $sheet->getStyle([1, 1, $lastColumn, 1])->getFont()->setBold(true);
        $sheet->freezePane('A2');

        $row = 2;
        foreach ($items as $item) {
            $organization = $item->getOrganization();
            $assignee = $item->getAssignedTo();

            $sheet->setCellValueExplicit([1, $row], (string) $item->getId(), DataType::TYPE_NUMERIC);
            $this->text($sheet, 2, $row, $item->getInventoryNumber());
            $this->text($sheet, 3, $row, $item->getName());
            $this->text($sheet, 4, $row, $item->getCategory()?->getName());
            $this->text($sheet, 5, $row, $organization->getPath());
            $this->text($sheet, 6, $row, $item->getSerialNumber());
            $this->text($sheet, 7, $row, $item->getStatus()->getLabel());
            $this->text($sheet, 8, $row, $this->assigneeName($assignee));

            $price = $item->getPrice();
            if ($price !== null) {
                $sheet->setCellValueExplicit([9, $row], $price, DataType::TYPE_NUMERIC);
            }

            $this->text($sheet, 10, $row, $item->getPurchasedAt()?->format('Y-m-d'));
            $this->text($sheet, 11, $row, $item->getUpd()?->getLabel());
            $this->text($sheet, 12, $row, $item->getDescription());

            ++$row;
        }

        foreach (range(1, $lastColumn) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    /**
     * Всё, кроме id и цены, пишем строкой: инвентарные номера вида «0012» Excel
     * иначе превратит в число и потеряет ведущие нули.
     */
    private function text(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $column, int $row, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $sheet->setCellValueExplicit([$column, $row], $value, DataType::TYPE_STRING);
    }

    private function assigneeName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $parts = array_filter([$user->getLastname(), $user->getFirstname(), $user->getPatronymic()]);

        return $parts === [] ? $user->getLogin() : implode(' ', $parts);
    }

    /**
     * Имя намеренно не `stream()`: в AbstractController уже есть protected-метод
     * с таким именем, и приватный одноимённый роняет загрузку класса целиком.
     */
    private function streamXlsx(Spreadsheet $spreadsheet): StreamedResponse
    {
        $filename = sprintf('inventory_%s.xlsx', (new \DateTimeImmutable())->format('Y-m-d'));

        $response = new StreamedResponse(static function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        });

        $response->headers->set(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        );
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }
}
