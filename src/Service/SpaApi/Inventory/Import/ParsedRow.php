<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Inventory\Import;

use App\Enum\Inventory\ImportRowType;

/**
 * Строка, извлечённая из 1С-выгрузки (до какого-либо матчинга с БД).
 */
final readonly class ParsedRow
{
    /**
     * @param array<string, string|float|int|null> $raw исходные значимые ячейки (колонка => значение)
     */
    public function __construct(
        public int $rowNo,
        public ImportRowType $type,
        public ?string $name = null,          // номенклатура (для type=nomenclature)
        public ?string $fio = null,           // ФИО сотрудника-держателя (employee_sheet)
        public ?string $subdivision = null,   // подразделение (stock_sheet, справочно)
        public ?string $warehouse = null,     // склад из файла (stock_sheet, справочно)
        public ?string $quantity = null,      // количество DECIMAL-строкой
        public ?string $factQuantity = null,  // фактическое наличие (stock_sheet, колонка H)
        public ?string $note = null,          // примечание/«выяснить» из файла
        public ?string $problem = null,       // код проблемы парсинга (null = ок)
        public array $raw = [],
    ) {
    }
}
