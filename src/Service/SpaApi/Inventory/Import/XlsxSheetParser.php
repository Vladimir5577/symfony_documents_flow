<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Inventory\Import;

use App\Enum\Inventory\ImportRowType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Парсер двух форматов 1С-выгрузок (образцы — E:\projects\kanban\invent\, копии в tests/fixtures).
 *
 * Структура определяется по outlineLevel строк (проверено спайком Ф0):
 *  - employee_sheet («Материалы, выданные сотрудникам»): level 0 = ФИО сотрудника (итоговая
 *    строка группы), level 1 = номенклатура под ним. Колонки дат в реальных выгрузках пусты,
 *    значимы A (название), F (количество), G (сумма).
 *  - stock_sheet (ОСВ по счёту): level 1 = склад, 2 = подразделение, 3 = номенклатура.
 *    Значимы A (название), F (сальдо на конец, Дебет — учётный остаток), H (фактическое
 *    наличие), L («Выяснить»), M (примечание). Файл может содержать формулы — читаем
 *    вычисленные значения.
 *
 * Парсер НИЧЕГО не знает о БД: только файл → список ParsedRow. Матчинг и персист — выше.
 */
final class XlsxSheetParser
{
    private const TOTAL_MARKERS = ['итого', 'всего'];

    /**
     * @return list<ParsedRow>
     */
    public function parseEmployeeSheet(string $path): array
    {
        $sheet = $this->loadSheet($path);
        $rows = [];
        $highestRow = $sheet->getHighestDataRow();

        for ($i = 1; $i <= $highestRow; $i++) {
            $level = $sheet->getRowDimension($i)->getOutlineLevel();
            $name = $this->str($sheet, 'A', $i);

            if ($name === null) {
                continue;
            }

            if ($level === 0) {
                // Шапка отчёта до первой группы, итоговые строки и ФИО-заголовки групп.
                if ($this->isReportHeader($name) || $i < $this->firstDataRow($sheet)) {
                    continue;
                }
                if ($this->isTotalRow($name)) {
                    $rows[] = new ParsedRow(rowNo: $i, type: ImportRowType::TOTAL, raw: ['A' => $name]);
                    continue;
                }
                $rows[] = new ParsedRow(
                    rowNo: $i,
                    type: ImportRowType::EMPLOYEE_HEADER,
                    fio: $this->normalizeSpaces($name),
                    quantity: $this->decimal($sheet, 'F', $i),
                    problem: $this->looksLikeFio($name) ? null : 'fio_suspicious',
                    raw: ['A' => $name, 'F' => $this->rawValue($sheet, 'F', $i), 'G' => $this->rawValue($sheet, 'G', $i)],
                );
                continue;
            }

            // level >= 1 — номенклатура, выданная текущему сотруднику
            $qty = $this->decimal($sheet, 'F', $i);
            $rows[] = new ParsedRow(
                rowNo: $i,
                type: ImportRowType::NOMENCLATURE,
                name: $this->normalizeSpaces($name),
                quantity: $qty,
                problem: $qty === null ? 'quantity_missing' : null,
                raw: [
                    'A' => $name,
                    'B' => $this->rawValue($sheet, 'B', $i),
                    'F' => $this->rawValue($sheet, 'F', $i),
                    'G' => $this->rawValue($sheet, 'G', $i),
                ],
            );
        }

        return $rows;
    }

    /**
     * @return list<ParsedRow>
     */
    public function parseStockSheet(string $path): array
    {
        $sheet = $this->loadSheet($path);
        $rows = [];
        $highestRow = $sheet->getHighestDataRow();
        $currentWarehouse = null;
        $currentSubdivision = null;

        for ($i = 1; $i <= $highestRow; $i++) {
            $level = $sheet->getRowDimension($i)->getOutlineLevel();
            $name = $this->str($sheet, 'A', $i);

            if ($name === null || $level === 0) {
                // Шапка отчёта, строка счёта («10») и финальный итог структурно не нужны.
                continue;
            }

            $name = $this->normalizeSpaces($name);

            if ($level === 1) {
                $currentWarehouse = $name;
                $currentSubdivision = null;
                $rows[] = new ParsedRow(rowNo: $i, type: ImportRowType::SKIP, warehouse: $name, raw: ['A' => $name]);
                continue;
            }

            if ($level === 2) {
                $currentSubdivision = $name;
                $rows[] = new ParsedRow(
                    rowNo: $i,
                    type: ImportRowType::SUBDIVISION,
                    subdivision: $name,
                    warehouse: $currentWarehouse,
                    raw: ['A' => $name],
                );
                continue;
            }

            // level >= 3 — номенклатура
            $qty = $this->decimal($sheet, 'F', $i); // сальдо на конец периода (Дебет) = учётный остаток
            $rows[] = new ParsedRow(
                rowNo: $i,
                type: ImportRowType::NOMENCLATURE,
                name: $name,
                subdivision: $currentSubdivision,
                warehouse: $currentWarehouse,
                quantity: $qty,
                factQuantity: $this->decimal($sheet, 'H', $i),
                note: $this->buildStockNote($sheet, $i),
                problem: $qty === null ? 'quantity_missing' : null,
                raw: [
                    'A' => $name,
                    'F' => $this->rawValue($sheet, 'F', $i),
                    'H' => $this->rawValue($sheet, 'H', $i),
                    'L' => $this->rawValue($sheet, 'L', $i),
                    'M' => $this->rawValue($sheet, 'M', $i),
                ],
            );
        }

        return $rows;
    }

    private function loadSheet(string $path): Worksheet
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(false); // outlineLevel хранится в row dimensions — data-only их не читает

        return $reader->load($path)->getActiveSheet();
    }

    private function str(Worksheet $sheet, string $col, int $row): ?string
    {
        $value = $sheet->getCell($col . $row)->getCalculatedValue();
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function rawValue(Worksheet $sheet, string $col, int $row): string|float|int|null
    {
        $value = $sheet->getCell($col . $row)->getCalculatedValue();

        return \is_scalar($value) || $value === null ? $value : (string) $value;
    }

    private function decimal(Worksheet $sheet, string $col, int $row): ?string
    {
        $value = $sheet->getCell($col . $row)->getCalculatedValue();
        if ($value === null || $value === '') {
            return null;
        }
        if (\is_int($value) || \is_float($value)) {
            return $this->formatDecimal((string) $value);
        }

        $normalized = str_replace([' ', "\u{A0}", ','], ['', '', '.'], trim((string) $value));

        return is_numeric($normalized) ? $this->formatDecimal($normalized) : null;
    }

    private function formatDecimal(string $number): string
    {
        // DECIMAL(14,3)-строка без экспоненты и хвостовых нулей сверх точности
        return rtrim(rtrim(number_format((float) $number, 3, '.', ''), '0'), '.') ?: '0';
    }

    private function buildStockNote(Worksheet $sheet, int $row): ?string
    {
        $clarify = $this->str($sheet, 'L', $row);
        $note = $this->str($sheet, 'M', $row);

        $parts = array_filter([
            $clarify !== null ? 'Выяснить: ' . $clarify : null,
            $note,
        ]);

        return $parts === [] ? null : implode('; ', $parts);
    }

    private function isTotalRow(string $name): bool
    {
        return \in_array(mb_strtolower(trim($name)), self::TOTAL_MARKERS, true);
    }

    private function isReportHeader(string $name): bool
    {
        $lower = mb_strtolower($name);

        return str_contains($lower, 'материалы, выданные')
            || str_contains($lower, 'ведомость')
            || str_contains($lower, 'сортировка')
            || str_contains($lower, 'отбор:')
            || str_contains($lower, 'выводимые данные')
            || str_contains($lower, 'гуп')
            || \in_array($lower, ['сотрудник', 'номенклатура', 'подразделение', 'склады', 'счет', 'счёт'], true);
    }

    private function looksLikeFio(string $value): bool
    {
        $words = preg_split('/\s+/u', trim($value)) ?: [];
        if (\count($words) < 2 || \count($words) > 4) {
            return false;
        }
        foreach ($words as $word) {
            if (!preg_match('/^[А-ЯЁа-яё][А-ЯЁа-яё\'-]*$/u', $word)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeSpaces(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Первая строка данных: после строки-шапки колонок «Сотрудник» (employee_sheet).
     */
    private function firstDataRow(Worksheet $sheet): int
    {
        for ($i = 1; $i <= min(20, $sheet->getHighestDataRow()); $i++) {
            if (mb_strtolower((string) $this->str($sheet, 'A', $i)) === 'номенклатура') {
                return $i + 1;
            }
        }

        return 8; // структура образца: данные с 8-й строки
    }
}
