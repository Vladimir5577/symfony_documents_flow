<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Проверяет ledger-инвариант модуля инвентаризации: `stock = Σ movement`.
 *
 * Команда объявлена в CLAUDE.md как механизм проверки этого инварианта, но не существовала —
 * при том, что на неё опирается приёмка. Здесь она появляется.
 *
 * Инвариант. `inventory_movement` — append-only журнал, `inventory_stock` — его свёртка,
 * которую пишет только проведение документов (UPSERT `ON CONFLICT`). Значит для каждого
 * разреза сумма `direction * quantity` по движениям обязана совпасть с остатком.
 * Разрез — те же пять колонок, что образуют ключ остатка `uniq_inv_stock_key`:
 * номенклатура, держатель-сотрудник, держатель-склад, управляющий склад, кабинет.
 *
 * Расхождение означает либо запись в остатки мимо проведения, либо потерянный/задвоенный
 * UPSERT при конкурентной работе. И то и другое — повод разбираться, а не «подровнять» цифру.
 *
 * Читает только БД, ничего не меняет: чинить расхождение автоматически нельзя,
 * непонятно, какая из двух сторон врёт.
 *
 * Сравнение в целых тысячных: масштаб схемы DECIMAL(14,3), сравнивать строки
 * («1» против «1.000») или float нельзя.
 */
#[AsCommand(
    name: 'app:inventory:verify-stock',
    description: 'Проверяет инвариант stock = Σ movement по каждому разрезу остатков; ничего не меняет.',
)]
final class InventoryVerifyStockCommand extends Command
{
    /** FULL OUTER JOIN: ловим и остаток без движений, и движения без остатка. */
    private const SQL = <<<'SQL'
        WITH ledger AS (
            SELECT
                nomenclature_id,
                holder_user_id,
                holder_warehouse_id,
                managing_warehouse_id,
                room_id,
                SUM(direction * quantity) AS qty
            FROM inventory_movement
            GROUP BY 1, 2, 3, 4, 5
        )
        SELECT
            COALESCE(s.nomenclature_id, l.nomenclature_id)             AS nomenclature_id,
            COALESCE(s.holder_user_id, l.holder_user_id)               AS holder_user_id,
            COALESCE(s.holder_warehouse_id, l.holder_warehouse_id)     AS holder_warehouse_id,
            COALESCE(s.managing_warehouse_id, l.managing_warehouse_id) AS managing_warehouse_id,
            COALESCE(s.room_id, l.room_id)                             AS room_id,
            COALESCE(s.quantity, 0)                                    AS stock_qty,
            COALESCE(l.qty, 0)                                         AS ledger_qty
        FROM inventory_stock s
        FULL OUTER JOIN ledger l
               ON  l.nomenclature_id                IS NOT DISTINCT FROM s.nomenclature_id
               AND l.holder_user_id                 IS NOT DISTINCT FROM s.holder_user_id
               AND l.holder_warehouse_id            IS NOT DISTINCT FROM s.holder_warehouse_id
               AND l.managing_warehouse_id          IS NOT DISTINCT FROM s.managing_warehouse_id
               AND l.room_id                        IS NOT DISTINCT FROM s.room_id
        WHERE COALESCE(s.quantity, 0) <> COALESCE(l.qty, 0)
        ORDER BY 1, 2, 3, 4, 5
        SQL;

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Проверка инварианта: stock = Σ movement');

        $rows = $this->connection->fetchAllAssociative(self::SQL);

        $totalStock = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_stock');
        $totalMovements = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_movement');
        $io->text(sprintf('Строк остатка: %d, движений: %d.', $totalStock, $totalMovements));

        if ($rows === []) {
            $io->success('Расхождений нет: каждый разрез остатка совпадает с суммой движений.');

            return Command::SUCCESS;
        }

        $io->table(
            ['номенклатура', 'держатель (польз.)', 'держатель (склад)', 'упр. склад', 'кабинет', 'остаток', 'по движениям', 'разница'],
            array_map(
                static fn (array $r): array => [
                    (string) $r['nomenclature_id'],
                    $r['holder_user_id'] === null ? '—' : (string) $r['holder_user_id'],
                    $r['holder_warehouse_id'] === null ? '—' : (string) $r['holder_warehouse_id'],
                    $r['managing_warehouse_id'] === null ? '—' : (string) $r['managing_warehouse_id'],
                    $r['room_id'] === null ? '—' : (string) $r['room_id'],
                    (string) $r['stock_qty'],
                    (string) $r['ledger_qty'],
                    bcsub((string) $r['stock_qty'], (string) $r['ledger_qty'], 3),
                ],
                $rows,
            ),
        );

        $io->error(sprintf(
            'Инвариант нарушен: разрезов с расхождением — %d. Автоматически НЕ исправляется: '
            . 'сначала надо понять, чья цифра неверна. Смотреть в сторону записи в inventory_stock '
            . 'мимо проведения документов и конкурентных проведений.',
            \count($rows),
        ));

        return Command::FAILURE;
    }
}
