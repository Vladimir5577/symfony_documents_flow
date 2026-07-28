<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Inventory\Reconciliation;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Nomenclature;
use App\Entity\Inventory\Reconciliation;
use App\Entity\Inventory\ReconciliationLine;
use App\Entity\Inventory\Warehouse;
use App\Entity\User\User;
use App\Enum\Inventory\ComparisonStatus;
use App\Enum\Inventory\ImportRowType;
use App\Enum\Inventory\ReconciliationStatus;
use App\Enum\Inventory\ResolutionStatus;
use App\Repository\Inventory\MovementRepository;
use App\Repository\Inventory\NomenclatureAliasRepository;
use App\Service\SpaApi\Inventory\Import\XlsxSheetParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Сверка остатков системы с оборотно-сальдовой ведомостью 1С.
 *
 * Смысл: показать бухгалтерии и МОЛу, где учёт разошёлся, и отделить настоящие расхождения
 * от разницы методик. Главный источник ложных расхождений — расходники: в системе выдача
 * расходника сразу списывает его с остатка, а в 1С он числится до акта. Такие строки
 * объясняются автоматически, чтобы человек разбирал только то, что действительно требует
 * разбирательства.
 *
 * Воспроизводимость: на момент расчёта фиксируется граница журнала движений
 * (`max_movement_id`). Движения append-only, поэтому пересчёт той же сверки через месяц
 * даст тот же результат, несмотря на новые проведения.
 */
final class ReconciliationService
{
    /** Масштаб количеств: DECIMAL(14,3). Сравнение — в целых тысячных, без float-погрешности. */
    private const QTY_SCALE = 1000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly XlsxSheetParser $parser,
        private readonly MovementRepository $movements,
        private readonly NomenclatureAliasRepository $nomenclatureAliases,
        #[Autowire('%private_upload_dir_inventory%')]
        private readonly string $uploadDir,
    ) {
    }

    /**
     * Разбор ОСВ, расчёт диффа и сохранение сверки со строками.
     */
    public function create(
        UploadedFile $file,
        \DateTimeImmutable $asOfDate,
        Warehouse $warehouse,
        User $actor,
    ): Reconciliation {
        $originalName = (string) $file->getClientOriginalName();

        // Разбираем ДО перемещения: если файл окажется битым, на диске не останется сироты.
        // Проверка по MIME и расширению ничего не гарантирует — достаточно переименовать
        // любой файл в .xlsx, поэтому исключение парсера превращаем в понятную 400,
        // а не в 500 со стектрейсом.
        try {
            // Формат A (ОСВ по складу) — тот же парсер, что и у импорта остатков.
            $parsed = $this->parser->parseStockSheet($file->getPathname());
        } catch (\Throwable $e) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_IMPORT_INVALID_FORMAT, $e);
        }

        $storedPath = $this->storeFile($file);

        $warehouseId = (int) $warehouse->getId();
        $maxMovementId = $this->movements->currentMaxId();

        $systemQty = $this->movements->qtyByNomenclatureAtDate($warehouseId, $asOfDate, $maxMovementId);
        $consumedQty = $this->movements->consumptionByNomenclatureUpTo($warehouseId, $asOfDate, $maxMovementId);

        $reconciliation = new Reconciliation();
        // Имя исходного файла в заголовке — чтобы через месяц было видно, из какой ОСВ вырос дифф.
        $reconciliation->setTitle(mb_substr(sprintf(
            'Сверка с 1С на %s — %s (%s)',
            $asOfDate->format('d.m.Y'),
            (string) $warehouse->getName(),
            $originalName,
        ), 0, 255));
        $reconciliation->setFilePath($storedPath);
        $reconciliation->setAsOfDate($asOfDate);
        $reconciliation->setWarehouse($warehouse);
        $reconciliation->setMaxMovementId($maxMovementId);
        $reconciliation->setStatus(ReconciliationStatus::DRAFT);
        $reconciliation->setCreatedBy($actor);
        $this->em->persist($reconciliation);

        // Свёртка файла по номенклатуре — обязательна, а не оптимизация.
        // ОСВ формата A группирует позиции по подразделениям, поэтому одна и та же
        // номенклатура встречается в файле несколько раз. Сравнение каждой такой строки
        // с ПОЛНЫМ остатком системы дало бы ложное расхождение на каждом повторе.
        // Правило свёртки из контракта: SUM GROUP BY nomenclature, без room/managing.
        /** @var array<int, array{nomenclature: Nomenclature, qty: int, rawName: string, subdivision: ?string, occurrences: int}> $fileRollup */
        $fileRollup = [];

        foreach ($parsed as $row) {
            if ($row->type !== ImportRowType::NOMENCLATURE || $row->name === null) {
                continue;
            }

            $nomenclature = $this->matchNomenclature($row->name);

            // Количество в файле не прочиталось. Считать его нулём нельзя: получится либо
            // ложное «сходится», либо расхождение выдуманного размера. Такую строку отдаём
            // человеку явно — с пустым qty_1c и пометкой «выяснить».
            if ($row->quantity === null) {
                $qtySystemRaw = $nomenclature === null
                    ? null
                    : ($systemQty[(int) $nomenclature->getId()] ?? '0');

                $unreadable = $this->addLine(
                    $reconciliation,
                    $row->name,
                    $row->subdivision,
                    $nomenclature,
                    null,
                    $qtySystemRaw,
                    $this->toThousandths($qtySystemRaw) === 0
                        ? ComparisonStatus::MISSING_IN_SYSTEM
                        : ComparisonStatus::QTY_MISMATCH,
                );
                $unreadable->setResolutionStatus(ResolutionStatus::TO_CLARIFY);
                $unreadable->setNote('Количество в файле 1С не прочиталось — проверьте строку в исходной выгрузке.');

                continue;
            }

            if ($nomenclature === null) {
                // Позицию не с чем сопоставлять — сворачивать её не по чему,
                // поэтому такие строки остаются отдельными, как в файле.
                $this->addLine(
                    $reconciliation,
                    $row->name,
                    $row->subdivision,
                    null,
                    $row->quantity,
                    null,
                    ComparisonStatus::MISSING_IN_SYSTEM,
                );
                continue;
            }

            $nomenclatureId = (int) $nomenclature->getId();
            if (!isset($fileRollup[$nomenclatureId])) {
                $fileRollup[$nomenclatureId] = [
                    'nomenclature' => $nomenclature,
                    'qty' => 0,
                    'rawName' => $row->name,
                    // Подразделение сохраняем только если позиция встретилась в файле один раз:
                    // у свёрнутой строки его нет, и подставлять первое попавшееся — врать.
                    'subdivision' => $row->subdivision,
                    'occurrences' => 0,
                ];
            }

            $fileRollup[$nomenclatureId]['qty'] += $this->toThousandths($row->quantity);
            $fileRollup[$nomenclatureId]['occurrences']++;
        }

        foreach ($fileRollup as $nomenclatureId => $entry) {
            $nomenclature = $entry['nomenclature'];
            $qty1c = $this->fromThousandths($entry['qty']);
            $qtySystem = $systemQty[$nomenclatureId] ?? '0';

            $line = $this->addLine(
                $reconciliation,
                $entry['rawName'],
                $entry['occurrences'] > 1 ? null : $entry['subdivision'],
                $nomenclature,
                $qty1c,
                $qtySystem,
                $this->compare($qty1c, $qtySystem),
            );

            $this->autoExplainConsumable($line, $nomenclature, $qty1c, $qtySystem, $consumedQty[$nomenclatureId] ?? '0');
        }

        // Позиции, которые числятся у нас, но в выгрузку 1С не попали.
        // Их не найти обходом строк файла — только полным срезом системы.
        $missingIds = [];
        foreach ($systemQty as $nomenclatureId => $qty) {
            if (!isset($fileRollup[$nomenclatureId]) && $this->toThousandths($qty) !== 0) {
                $missingIds[] = $nomenclatureId;
            }
        }

        foreach ($this->findNomenclaturesIncludingDeleted($missingIds) as $nomenclatureId => $nomenclature) {
            $this->addLine(
                $reconciliation,
                (string) $nomenclature->getName(),
                null,
                $nomenclature,
                null,
                $systemQty[$nomenclatureId],
                ComparisonStatus::MISSING_IN_1C,
            );
        }

        $this->em->flush();

        return $reconciliation;
    }

    /**
     * Ручное решение по строке: пометить «выяснить», объяснить или переназначить номенклатуру.
     */
    public function updateLine(
        ReconciliationLine $line,
        ?ResolutionStatus $resolution,
        ?string $note,
        ?Nomenclature $nomenclature,
    ): ReconciliationLine {
        if ($resolution !== null) {
            $line->setResolutionStatus($resolution);
        }
        if ($note !== null) {
            $line->setNote(trim($note) === '' ? null : trim($note));
        }
        if ($nomenclature !== null) {
            $line->setNomenclature($nomenclature);
        }

        $reconciliation = $line->getReconciliation();
        if ($reconciliation !== null && $reconciliation->getStatus() === ReconciliationStatus::DRAFT) {
            // Как только человек начал разбирать расхождения, сверка перестаёт быть черновиком.
            $reconciliation->setStatus(ReconciliationStatus::IN_PROGRESS);
        }

        $this->em->flush();

        return $line;
    }

    /**
     * Авто-объяснение расхождений по расходникам.
     *
     * В системе выдача расходника сотруднику — сразу расход, позиция уходит с остатка.
     * В 1С она может числиться до акта списания. Если 1С показывает больше ровно на то
     * количество, которое мы провели расходом, — это не пропажа, а разница методик учёта.
     * Такую строку помечаем объяснённой, чтобы человек разбирал только настоящие расхождения.
     *
     * Требование равенства строгое: «примерно сходится» здесь хуже, чем не объяснить вовсе —
     * молча закрытое настоящее расхождение дороже лишней строки в списке на разбор.
     *
     * Несущее допущение: расход суммируется БЕЗ нижней границы периода, потому что правило
     * рассчитано на ситуацию «1С расходники не списывает вовсе». Если 1С часть уже списала,
     * равенство не сойдётся и строка уйдёт человеку — ошибка в безопасную сторону.
     * Когда появятся регулярные сверки, нижней границей логично взять дату предыдущей.
     */
    private function autoExplainConsumable(
        ReconciliationLine $line,
        Nomenclature $nomenclature,
        ?string $qty1c,
        ?string $qtySystem,
        string $consumed,
    ): void {
        if (!$nomenclature->isConsumable() || $line->getComparisonStatus() !== ComparisonStatus::QTY_MISMATCH) {
            return;
        }

        // Отрицательный остаток в системе — всегда поломка данных (движения разъехались
        // с проекцией остатков), а не разница методик учёта. Объяснять его расходом нельзя:
        // иначе арифметика сойдётся и настоящая проблема уедет в «объяснено».
        // Найдено состязательным ревью (Codex): qty1c=0, qtySystem=-10, consumed=10
        // давало diff=+10 == consumed и помечало строку explained.
        $systemThousandths = $this->toThousandths($qtySystem);
        if ($systemThousandths < 0) {
            return;
        }

        $diff = $this->toThousandths($qty1c) - $systemThousandths;
        if ($diff <= 0 || $diff !== $this->toThousandths($consumed)) {
            return;
        }

        $line->setResolutionStatus(ResolutionStatus::EXPLAINED);
        $line->setNote(sprintf(
            'Расхождение объяснено автоматически: %s ед. выдано в расход в системе, в 1С позиция числится до акта списания.',
            rtrim(rtrim($consumed, '0'), '.') ?: $consumed,
        ));
    }

    private function addLine(
        Reconciliation $reconciliation,
        string $rawName,
        ?string $subdivision,
        ?Nomenclature $nomenclature,
        ?string $qty1c,
        ?string $qtySystem,
        ComparisonStatus $status,
    ): ReconciliationLine {
        $line = new ReconciliationLine();
        $line->setReconciliation($reconciliation);
        $line->setRawName(mb_substr($rawName, 0, 500));
        $line->setRawSubdivision($subdivision);
        $line->setNomenclature($nomenclature);
        $line->setQty1c($qty1c);
        $line->setQtySystem($qtySystem);
        $line->setComparisonStatus($status);
        $line->setResolutionStatus(ResolutionStatus::NONE);

        $reconciliation->addLine($line);
        $this->em->persist($line);

        return $line;
    }

    private function compare(?string $qty1c, ?string $qtySystem): ComparisonStatus
    {
        $in1c = $this->toThousandths($qty1c);
        $inSystem = $this->toThousandths($qtySystem);

        if ($in1c === $inSystem) {
            return ComparisonStatus::OK;
        }

        // 1С показывает позицию, у нас её на складе нет вовсе — это не «разошлось количество»,
        // а отсутствие позиции: для разбора это разные ситуации.
        if ($inSystem === 0) {
            return ComparisonStatus::MISSING_IN_SYSTEM;
        }

        return ComparisonStatus::QTY_MISMATCH;
    }

    /**
     * Количество в целых тысячных.
     *
     * Сравнивать decimal-строки напрямую нельзя («10» и «10.000» — разные строки при равных
     * количествах), а float даёт погрешность. Масштаб фиксирован схемой: DECIMAL(14,3).
     */
    private function toThousandths(?string $qty): int
    {
        if ($qty === null || trim($qty) === '') {
            return 0;
        }

        return (int) round((float) str_replace(',', '.', $qty) * self::QTY_SCALE);
    }

    /**
     * Номенклатура по списку id, ВКЛЮЧАЯ мягко удалённую.
     *
     * Номенклатура soft-deletable, а движения — нет. Если позицию удалили из справочника,
     * её остаток никуда не делся, и в сверке она обязана быть видна: обычный `find()`
     * под фильтром Gedmo вернул бы null, и позиция с ненулевым остатком молча исчезла бы
     * из сверки — худший исход для инструмента, смысл которого в доверии к цифрам.
     *
     * Один запрос вместо `find()` в цикле — заодно снимает N+1.
     *
     * @param list<int> $ids
     *
     * @return array<int, Nomenclature>
     */
    private function findNomenclaturesIncludingDeleted(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $filters = $this->em->getFilters();
        $wasEnabled = $filters->isEnabled('softdeleteable');
        if ($wasEnabled) {
            $filters->disable('softdeleteable');
        }

        try {
            /** @var list<Nomenclature> $rows */
            $rows = $this->em->createQuery(
                'SELECT n FROM App\Entity\Inventory\Nomenclature n WHERE n.id IN (:ids)',
            )->setParameter('ids', $ids)->getResult();
        } finally {
            if ($wasEnabled) {
                $filters->enable('softdeleteable');
            }
        }

        $byId = [];
        foreach ($rows as $nomenclature) {
            $byId[(int) $nomenclature->getId()] = $nomenclature;
        }

        return $byId;
    }

    /** Обратное преобразование: целые тысячные → decimal-строка схемы DECIMAL(14,3). */
    private function fromThousandths(int $value): string
    {
        return number_format($value / self::QTY_SCALE, 3, '.', '');
    }

    private function matchNomenclature(string $rawName): ?Nomenclature
    {
        $alias = $this->nomenclatureAliases->findByRawName($rawName);
        if ($alias !== null) {
            return $alias->getNomenclature();
        }

        $matches = $this->em->createQuery(
            'SELECT n FROM App\Entity\Inventory\Nomenclature n WHERE LOWER(n.name) = :name',
        )->setParameter('name', mb_strtolower($rawName))->setMaxResults(2)->getResult();

        // Неоднозначное совпадение оставляем человеку: сверка — про доверие к цифрам,
        // угаданная не та позиция подрывает его сильнее, чем строка «нет в системе».
        return \count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * ОСВ сохраняется: без исходного файла невозможно объяснить, откуда взялся дифф.
     */
    private function storeFile(UploadedFile $file): string
    {
        $directory = rtrim($this->uploadDir, '/\\') . \DIRECTORY_SEPARATOR . 'reconciliations';
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_INVALID_PAYLOAD);
        }

        $name = sprintf('%s-%s.xlsx', date('Ymd-His'), bin2hex(random_bytes(4)));
        $file->move($directory, $name);

        return $directory . \DIRECTORY_SEPARATOR . $name;
    }
}
