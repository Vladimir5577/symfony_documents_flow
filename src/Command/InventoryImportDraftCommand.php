<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Inventory\Category;
use App\Entity\Inventory\Document;
use App\Entity\Inventory\DocumentLine;
use App\Entity\Inventory\ImportBatch;
use App\Entity\Inventory\ImportRow;
use App\Entity\Inventory\Nomenclature;
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
use App\Repository\Inventory\NomenclatureRepository;
use App\Repository\Inventory\WarehouseRepository;
use App\Service\SpaApi\Inventory\Import\ParsedRow;
use App\Service\SpaApi\Inventory\Import\XlsxSheetParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ф1: консольный импорт 1С-выгрузки в ЧЕРНОВИК документа «Ввод начальных остатков»
 * (UI-мастер импорта — Ф3, поверх тех же staging-таблиц).
 *
 * employee_sheet: остатки кладутся на сопоставленных сотрудников; контур (managing) —
 * склад из --managing-warehouse. stock_sheet: остатки кладутся на склад --target-warehouse.
 * Несопоставленные строки остаются в staging со статусом unmatched; их добор — через
 * UI-мастер импорта (Ф3, apply по batch-id) — повторный запуск того же файла консолью
 * блокируется hash-гейтом намеренно. Проведение черновика — только руками админа в UI.
 */
#[AsCommand(
    name: 'app:inventory:import-draft',
    description: 'Импорт 1С-выгрузки (XLSX) в черновик документа «Ввод начальных остатков»',
)]
final class InventoryImportDraftCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly XlsxSheetParser $parser,
        private readonly ImportBatchRepository $batches,
        private readonly NomenclatureRepository $nomenclatures,
        private readonly NomenclatureAliasRepository $nomenclatureAliases,
        private readonly EmployeeAliasRepository $employeeAliases,
        private readonly WarehouseRepository $warehouses,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Путь к XLSX-файлу выгрузки')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'stock_sheet | employee_sheet', ImportFormat::EMPLOYEE_SHEET->value)
            ->addOption('managing-warehouse', null, InputOption::VALUE_REQUIRED, 'ID склада-контура для остатков на сотрудниках (employee_sheet)')
            ->addOption('target-warehouse', null, InputOption::VALUE_REQUIRED, 'ID склада-приёмника (stock_sheet)')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'ID категории для автосоздаваемой номенклатуры')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'ID пользователя-автора черновика (обязателен)')
            ->addOption('create-nomenclature', null, InputOption::VALUE_NONE, 'Автосоздавать отсутствующую номенклатуру (иначе строка → unmatched)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $path = (string) $input->getArgument('file');
        if (!is_file($path)) {
            $io->error('Файл не найден: ' . $path);

            return Command::FAILURE;
        }

        $format = ImportFormat::tryFrom((string) $input->getOption('format'));
        if ($format === null) {
            $io->error('format: stock_sheet | employee_sheet');

            return Command::FAILURE;
        }

        $actor = $this->em->find(User::class, (int) $input->getOption('actor'));
        if ($actor === null) {
            $io->error('--actor: укажите ID существующего пользователя (автор черновика)');

            return Command::FAILURE;
        }

        $managingWarehouse = null;
        $targetWarehouse = null;
        if ($format === ImportFormat::EMPLOYEE_SHEET) {
            $managingWarehouse = $this->warehouses->find((int) $input->getOption('managing-warehouse'));
            if ($managingWarehouse === null) {
                $io->error('--managing-warehouse: укажите ID склада-контура (консенсус: у остатка на сотруднике обязателен контур МОЛа)');

                return Command::FAILURE;
            }
        } else {
            $targetWarehouse = $this->warehouses->find((int) $input->getOption('target-warehouse'));
            if ($targetWarehouse === null) {
                $io->error('--target-warehouse: укажите ID склада-приёмника остатков ОСВ');

                return Command::FAILURE;
            }
        }

        $autoCategory = null;
        if ($input->getOption('create-nomenclature')) {
            $autoCategory = $this->em->find(Category::class, (int) $input->getOption('category'));
            if ($autoCategory === null) {
                $io->error('--category обязана указывать существующую категорию при --create-nomenclature');

                return Command::FAILURE;
            }
        }

        $hash = hash_file('sha256', $path);
        if ($this->batches->findOneBy(['fileHash' => $hash]) !== null) {
            $io->error('Этот файл уже импортировался (hash совпал) — повторное применение запрещено.');

            return Command::FAILURE;
        }

        $parsed = $format === ImportFormat::EMPLOYEE_SHEET
            ? $this->parser->parseEmployeeSheet($path)
            : $this->parser->parseStockSheet($path);

        $batch = new ImportBatch();
        $batch->setFormat($format);
        $batch->setFilename(basename($path));
        $batch->setFileHash($hash);
        $batch->setTargetWarehouse($targetWarehouse ?? $managingWarehouse);
        $batch->setStatus(ImportBatchStatus::PARSED);
        $batch->setCreatedBy($actor);
        $this->em->persist($batch);

        $document = new Document();
        $document->setType(DocumentType::INITIAL_BALANCE);
        $document->setStatus(DocumentStatus::DRAFT);
        $document->setNumber(Document::DRAFT_NUMBER_PREFIX . bin2hex(random_bytes(6)));
        $document->setDocDate(new \DateTimeImmutable());
        $document->setDefaultManagingWarehouse($managingWarehouse);
        $document->setComment(sprintf('Импорт «%s» (%s)', basename($path), $format->value));
        $document->setCreatedBy($actor);
        $this->em->persist($document);

        $stats = ['ready' => 0, 'unmatched' => 0, 'skipped' => 0];
        $currentEmployee = null;
        $currentEmployeeRaw = null;

        foreach ($parsed as $row) {
            if ($row->type === ImportRowType::EMPLOYEE_HEADER) {
                $currentEmployeeRaw = $row->fio;
                $currentEmployee = $row->fio === null ? null : $this->matchEmployee($row->fio);
            }

            $importRow = $this->stageRow($batch, $row, $currentEmployeeRaw);

            if ($row->type !== ImportRowType::NOMENCLATURE) {
                $stats['skipped']++;
                continue;
            }
            if ($row->quantity === null || (float) $row->quantity <= 0) {
                $importRow->setStatus(ImportRowStatus::REJECTED);
                $importRow->setProblem($row->problem ?? 'quantity_missing');
                $stats['skipped']++;
                continue;
            }

            $nomenclature = $this->matchNomenclature((string) $row->name, $autoCategory);
            $employeeRequired = $format === ImportFormat::EMPLOYEE_SHEET;

            if ($nomenclature === null || ($employeeRequired && $currentEmployee === null)) {
                $importRow->setStatus(ImportRowStatus::UNMATCHED);
                $importRow->setProblem($nomenclature === null ? 'nomenclature_not_found' : 'employee_not_found');
                $stats['unmatched']++;
                continue;
            }

            $importRow->setMatchedNomenclature($nomenclature);
            $importRow->setMatchedUser($currentEmployee);
            $importRow->setStatus(ImportRowStatus::READY);

            $line = new DocumentLine();
            $line->setDocument($document);
            $line->setNomenclature($nomenclature);
            $line->setQuantity($row->quantity);
            if ($employeeRequired) {
                $line->setToHolderUser($currentEmployee);
                $line->setToManagingWarehouse($managingWarehouse);
            } else {
                $line->setToHolderWarehouse($targetWarehouse);
            }
            if ($row->note !== null) {
                $line->setNote(mb_substr($row->note, 0, 500));
            }
            $document->addLine($line);
            $this->em->persist($line);
            $importRow->setStatus(ImportRowStatus::APPLIED);
            $stats['ready']++;
        }

        $nomenclatureRows = $stats['ready'] + $stats['unmatched'];
        $batch->setTotalsExpected($nomenclatureRows);
        $batch->setTotalsRecognized($stats['ready']);
        $batch->setTotalsRejected($stats['unmatched']);
        $batch->setStatus($stats['unmatched'] > 0 ? ImportBatchStatus::PARTIALLY_APPLIED : ImportBatchStatus::APPLIED);

        $this->em->flush();

        $io->success(sprintf(
            'Батч #%d: строк номенклатуры %d, в черновик №%s вошло %d, несопоставлено %d (staging), служебных %d.',
            (int) $batch->getId(),
            $nomenclatureRows,
            (string) $document->getNumber(),
            $stats['ready'],
            $stats['unmatched'],
            $stats['skipped'],
        ));
        $io->note('Контрольная сумма: recognized + rejected = expected → '
            . $stats['ready'] . ' + ' . $stats['unmatched'] . ' = ' . $nomenclatureRows);
        $io->note('Черновик проводится админом в UI после проверки. Несопоставленные строки — в inventory_import_row (status=unmatched).');

        return Command::SUCCESS;
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
        $this->em->persist($importRow);

        return $importRow;
    }

    private function matchNomenclature(string $rawName, ?Category $autoCategory): ?Nomenclature
    {
        $alias = $this->nomenclatureAliases->findByRawName($rawName);
        if ($alias !== null) {
            return $alias->getNomenclature();
        }

        // регистронезависимое точное совпадение — 1С-выгрузки гуляют регистром (ревью 2026-07-28)
        $matches = $this->em->createQuery(
            'SELECT n FROM App\Entity\Inventory\Nomenclature n WHERE LOWER(n.name) = :name',
        )->setParameter('name', mb_strtolower($rawName))->setMaxResults(2)->getResult();
        if (\count($matches) === 1) {
            return $matches[0];
        }
        if (\count($matches) > 1) {
            return null; // неоднозначно — в unmatched, решит человек
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

        // точное совпадение «Фамилия Имя Отчество» (без нечёткого матчинга — консенсус дебатов)
        $result = $this->em->createQuery(
            'SELECT u FROM App\Entity\User\User u
             WHERE LOWER(TRIM(CONCAT(u.lastname, \' \', u.firstname, \' \', COALESCE(u.patronymic, \'\')))) = :fio',
        )
            ->setParameter('fio', mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $rawFio))))
            ->getResult();

        return \count($result) === 1 ? $result[0] : null;
    }
}
