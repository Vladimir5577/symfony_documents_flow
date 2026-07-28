<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Inventory\Import;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Category;
use App\Entity\Inventory\Document;
use App\Entity\Inventory\DocumentLine;
use App\Entity\Inventory\EmployeeAlias;
use App\Entity\Inventory\ImportBatch;
use App\Entity\Inventory\ImportRow;
use App\Entity\Inventory\Nomenclature;
use App\Entity\Inventory\NomenclatureAlias;
use App\Entity\Inventory\Warehouse;
use App\Entity\User\User;
use App\Enum\Inventory\DocumentStatus;
use App\Enum\Inventory\DocumentType;
use App\Enum\Inventory\ImportBatchStatus;
use App\Enum\Inventory\ImportFormat;
use App\Enum\Inventory\ImportRowStatus;
use App\Enum\Inventory\ImportRowType;
use App\Repository\Inventory\EmployeeAliasRepository;
use App\Repository\Inventory\ImportBatchRepository;
use App\Repository\Inventory\NomenclatureAliasRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Импорт выгрузок 1С: разбор файла, staging строк, ручной маппинг, сборка черновика.
 *
 * Ядро вынесено из команды `app:inventory:import-draft`, но с одним принципиальным отличием:
 * команда делает разбор и сборку черновика ОДНИМ проходом, а API их разделяет.
 * Сначала человек видит, что распозналось, доматчивает проблемные строки — и только потом
 * строки превращаются в документ. Иначе неузнанные позиции молча теряются.
 */
final class XlsxImportService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly XlsxSheetParser $parser,
        private readonly ImportBatchRepository $batches,
        private readonly NomenclatureAliasRepository $nomenclatureAliases,
        private readonly EmployeeAliasRepository $employeeAliases,
    ) {
    }

    /**
     * Разбор файла и staging строк. Документ здесь НЕ создаётся.
     *
     * @param Warehouse $warehouse для `employee_sheet` — склад-контур МОЛа (остаток на сотруднике
     *                             без контура запрещён), для `stock_sheet` — склад-приёмник.
     *                             Оба лежат в одном поле `ImportBatch::targetWarehouse` — так
     *                             устроена сущность; смысл поля задаётся форматом батча.
     * @param Category|null $autoCategory категория для автосоздания отсутствующей номенклатуры;
     *                                    null — неизвестные позиции уходят в unmatched
     */
    public function stage(
        string $path,
        string $originalFilename,
        ImportFormat $format,
        Warehouse $warehouse,
        ?Category $autoCategory,
        User $actor,
    ): ImportBatch {
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_IMPORT_INVALID_FORMAT);
        }

        // Повторная загрузка того же файла — почти всегда человеческая ошибка,
        // а её цена высока: дублирующий приход остатков.
        if ($this->batches->findOneBy(['fileHash' => $hash]) !== null) {
            throw new ConflictHttpException(SpaApiError::INVENTORY_IMPORT_DUPLICATE_FILE);
        }

        $parsed = $format === ImportFormat::EMPLOYEE_SHEET
            ? $this->parser->parseEmployeeSheet($path)
            : $this->parser->parseStockSheet($path);

        $batch = new ImportBatch();
        $batch->setFormat($format);
        $batch->setFilename($originalFilename);
        $batch->setFileHash($hash);
        $batch->setTargetWarehouse($warehouse);
        $batch->setStatus(ImportBatchStatus::PARSED);
        $batch->setCreatedBy($actor);
        $this->em->persist($batch);

        $employeeRequired = $format === ImportFormat::EMPLOYEE_SHEET;
        $currentEmployee = null;
        $currentEmployeeRaw = null;
        $ready = 0;
        $unmatched = 0;

        foreach ($parsed as $row) {
            if ($row->type === ImportRowType::EMPLOYEE_HEADER) {
                $currentEmployeeRaw = $row->fio;
                $currentEmployee = $row->fio === null ? null : $this->matchEmployee($row->fio);
            }

            $importRow = $this->stageRow($batch, $row, $currentEmployeeRaw);

            // Служебные строки (заголовки, итоги) остаются в staging для прослеживаемости,
            // но в контрольную сумму не входят: сверяется только номенклатура.
            if ($row->type !== ImportRowType::NOMENCLATURE) {
                continue;
            }

            if ($row->quantity === null || (float) $row->quantity <= 0) {
                $importRow->setStatus(ImportRowStatus::REJECTED);
                $importRow->setProblem($row->problem ?? 'quantity_missing');
                continue;
            }

            $nomenclature = $this->matchNomenclature((string) $row->name, $autoCategory);
            if ($nomenclature === null || ($employeeRequired && $currentEmployee === null)) {
                $importRow->setStatus(ImportRowStatus::UNMATCHED);
                $importRow->setProblem(
                    $nomenclature === null ? 'nomenclature_not_found' : 'employee_not_found',
                );
                $unmatched++;
                continue;
            }

            $importRow->setMatchedNomenclature($nomenclature);
            $importRow->setMatchedUser($currentEmployee);
            $importRow->setStatus(ImportRowStatus::READY);
            $ready++;
        }

        $batch->setTotalsExpected($ready + $unmatched);
        $batch->setTotalsRecognized($ready);
        $batch->setTotalsRejected($unmatched);

        $this->em->flush();

        return $batch;
    }

    /**
     * Сборка черновика «Ввод остатков» из строк со статусом `ready`.
     *
     * Повторный вызов создаёт НОВЫЙ черновик из строк, доматченных вручную после прошлого apply.
     * Дописывать в прежний документ нельзя: он мог быть уже проведён, а проведённый заморожен.
     */
    public function apply(ImportBatch $batch, User $actor): Document
    {
        $rows = array_values(array_filter(
            $batch->getRows()->toArray(),
            static fn (ImportRow $row): bool => $row->getStatus() === ImportRowStatus::READY,
        ));

        if ($rows === []) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_DOCUMENT_INVALID_LINES);
        }

        $format = $batch->getFormat();
        $employeeRequired = $format === ImportFormat::EMPLOYEE_SHEET;
        $warehouse = $batch->getTargetWarehouse();

        $document = new Document();
        $document->setType(DocumentType::INITIAL_BALANCE);
        $document->setStatus(DocumentStatus::DRAFT);
        // Колонка number — NOT NULL UNIQUE, поэтому черновик получает временный номер;
        // настоящий выдаст счётчик при проведении (StockPostingService).
        $document->setNumber(Document::DRAFT_NUMBER_PREFIX . bin2hex(random_bytes(6)));
        $document->setDocDate(new \DateTimeImmutable());
        $document->setComment(sprintf(
            'Импорт «%s» (батч #%d, %s)',
            (string) $batch->getFilename(),
            (int) $batch->getId(),
            $format?->value ?? '—',
        ));
        $document->setCreatedBy($actor);
        if ($employeeRequired) {
            $document->setDefaultManagingWarehouse($warehouse);
        }
        $this->em->persist($document);

        foreach ($rows as $row) {
            $line = new DocumentLine();
            $line->setDocument($document);
            $line->setNomenclature($row->getMatchedNomenclature());
            $line->setQuantity((string) $row->getQuantity());

            if ($employeeRequired) {
                $line->setToHolderUser($row->getMatchedUser());
                $line->setToManagingWarehouse($warehouse);
            } else {
                $line->setToHolderWarehouse($warehouse);
            }

            $document->addLine($line);
            $this->em->persist($line);

            $row->setStatus(ImportRowStatus::APPLIED);
        }

        $batch->setStatus(
            $this->hasUnmatched($batch) ? ImportBatchStatus::PARTIALLY_APPLIED : ImportBatchStatus::APPLIED,
        );

        $this->em->flush();

        return $document;
    }

    /**
     * Ручной маппинг проблемной строки.
     *
     * Побочный эффект намеренный: сопоставление запоминается в alias-таблицу, поэтому
     * следующая выгрузка с тем же написанием распознается сама. Ради этого импорт и делится
     * на два шага — накопленные alias'ы удешевляют каждую следующую загрузку.
     */
    public function remapRow(
        ImportRow $row,
        ?Nomenclature $nomenclature,
        ?User $user,
        User $actor,
    ): ImportRow {
        if ($nomenclature !== null) {
            $row->setMatchedNomenclature($nomenclature);
            $this->rememberNomenclatureAlias((string) $row->getRawName(), $nomenclature, $actor);
        }

        if ($user !== null) {
            $row->setMatchedUser($user);
            $rawFio = $row->getRawFio();
            if ($rawFio !== null && trim($rawFio) !== '') {
                $this->rememberEmployeeAlias($rawFio, $user, $actor);
            }
        }

        $this->recalculateRowStatus($row);
        $this->refreshBatchTotals($row->getBatch());

        $this->em->flush();

        return $row;
    }

    private function recalculateRowStatus(ImportRow $row): void
    {
        // Уже вошедшую в документ строку не трогаем: движение по ней либо создано,
        // либо будет создано проведением — переигрывать это маппингом нельзя.
        if ($row->getStatus() === ImportRowStatus::APPLIED) {
            return;
        }

        $batch = $row->getBatch();
        $employeeRequired = $batch?->getFormat() === ImportFormat::EMPLOYEE_SHEET;

        $hasQuantity = $row->getQuantity() !== null && (float) $row->getQuantity() > 0;
        $hasNomenclature = $row->getMatchedNomenclature() !== null;
        $hasEmployee = !$employeeRequired || $row->getMatchedUser() !== null;

        if ($hasQuantity && $hasNomenclature && $hasEmployee) {
            $row->setStatus(ImportRowStatus::READY);
            $row->setProblem(null);

            return;
        }

        if (!$hasQuantity) {
            $row->setStatus(ImportRowStatus::REJECTED);
            $row->setProblem('quantity_missing');

            return;
        }

        $row->setStatus(ImportRowStatus::UNMATCHED);
        $row->setProblem($hasNomenclature ? 'employee_not_found' : 'nomenclature_not_found');
    }

    /**
     * Контрольная сумма батча: recognized + rejected = expected.
     * Пересчитывается после каждого маппинга, иначе счётчики на экране разъезжаются с фактом.
     */
    private function refreshBatchTotals(?ImportBatch $batch): void
    {
        if ($batch === null) {
            return;
        }

        $recognized = 0;
        $rejected = 0;

        foreach ($batch->getRows() as $row) {
            if ($row->getRowType() !== ImportRowType::NOMENCLATURE) {
                continue;
            }

            $status = $row->getStatus();
            if ($status === ImportRowStatus::READY || $status === ImportRowStatus::APPLIED) {
                $recognized++;
            } elseif ($status === ImportRowStatus::UNMATCHED) {
                $rejected++;
            }
        }

        $batch->setTotalsRecognized($recognized);
        $batch->setTotalsRejected($rejected);
        $batch->setTotalsExpected($recognized + $rejected);
    }

    private function hasUnmatched(ImportBatch $batch): bool
    {
        foreach ($batch->getRows() as $row) {
            if ($row->getStatus() === ImportRowStatus::UNMATCHED) {
                return true;
            }
        }

        return false;
    }

    private function stageRow(ImportBatch $batch, ParsedRow $row, ?string $currentEmployeeRaw): ImportRow
    {
        $importRow = new ImportRow();
        $importRow->setBatch($batch);
        $importRow->setRowNo($row->rowNo);
        $importRow->setRowType($row->type);
        $importRow->setRaw($row->raw);
        $importRow->setRawName($row->name);
        $importRow->setRawFio($row->type === ImportRowType::EMPLOYEE_HEADER ? $row->fio : $currentEmployeeRaw);
        $importRow->setRawSubdivision($row->subdivision);
        $importRow->setQuantity($row->quantity);
        $importRow->setProblem($row->problem);
        $importRow->setStatus(ImportRowStatus::UNMATCHED);
        $batch->addRow($importRow);
        $this->em->persist($importRow);

        return $importRow;
    }

    private function matchNomenclature(string $rawName, ?Category $autoCategory): ?Nomenclature
    {
        $alias = $this->nomenclatureAliases->findByRawName($rawName);
        if ($alias !== null) {
            return $alias->getNomenclature();
        }

        // Регистронезависимое точное совпадение: выгрузки 1С гуляют регистром.
        $matches = $this->em->createQuery(
            'SELECT n FROM App\Entity\Inventory\Nomenclature n WHERE LOWER(n.name) = :name',
        )->setParameter('name', mb_strtolower($rawName))->setMaxResults(2)->getResult();

        if (\count($matches) === 1) {
            return $matches[0];
        }
        if (\count($matches) > 1) {
            return null; // неоднозначно — решает человек, автоматика тут вредна
        }

        if ($autoCategory === null) {
            return null;
        }

        $nomenclature = new Nomenclature();
        $nomenclature->setName($rawName);
        $nomenclature->setCategory($autoCategory);
        $this->em->persist($nomenclature);

        return $nomenclature;
    }

    private function matchEmployee(string $rawFio): ?User
    {
        $alias = $this->employeeAliases->findByRawFio($rawFio);
        if ($alias !== null) {
            return $alias->getUser();
        }

        // Только точное совпадение ФИО. Нечёткий матчинг здесь опаснее пропуска:
        // ошибка привяжет чужое имущество к человеку, и найдётся это только на инвентаризации.
        $result = $this->em->createQuery(
            'SELECT u FROM App\Entity\User\User u
             WHERE LOWER(TRIM(CONCAT(u.lastname, \' \', u.firstname, \' \', COALESCE(u.patronymic, \'\')))) = :fio',
        )
            ->setParameter('fio', mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $rawFio))))
            ->getResult();

        return \count($result) === 1 ? $result[0] : null;
    }

    private function rememberNomenclatureAlias(string $rawName, Nomenclature $nomenclature, User $actor): void
    {
        if (trim($rawName) === '' || $this->nomenclatureAliases->findByRawName($rawName) !== null) {
            return;
        }

        $alias = new NomenclatureAlias();
        $alias->setRawName($rawName);
        $alias->setNomenclature($nomenclature);
        $alias->setConfirmedBy($actor);
        $this->em->persist($alias);
    }

    private function rememberEmployeeAlias(string $rawFio, User $user, User $actor): void
    {
        if ($this->employeeAliases->findByRawFio($rawFio) !== null) {
            return;
        }

        $alias = new EmployeeAlias();
        $alias->setRawFio($rawFio);
        $alias->setUser($user);
        $alias->setConfirmedBy($actor);
        $this->em->persist($alias);
    }
}
