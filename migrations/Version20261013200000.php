<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Закупки: сумма заявки в колонке для фильтра списка «от суммы».
 *
 * Фильтр считал сумму позиций подзапросом по каждой заявке, и COUNT на 100k
 * заявок шёл ~460 мс (замер 13.10). Дальше колонку пишет
 * PurchaseRequest::syncTotalAmount(), здесь — заполнение по тем же правилам:
 * без снятых позиций, позиция со склада бесплатна, количество — утверждённое,
 * каждая позиция округляется до копейки (половина вверх; цены и количества
 * неотрицательные, поэтому ROUND Postgres даёт то же, что PHP).
 */
final class Version20261013200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purchase request: total_amount_kopecks column with backfill and index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_request ADD total_amount_kopecks BIGINT DEFAULT 0 NOT NULL');
        $this->addSql(<<<'SQL'
            UPDATE purchase_request pr
            SET total_amount_kopecks = s.total
            FROM (
                SELECT purchase_request_id,
                       SUM(ROUND(estimated_price * COALESCE(approved_quantity, quantity), 2) * 100)::bigint AS total
                FROM purchase_request_item
                WHERE excluded = false AND in_stock = false
                GROUP BY purchase_request_id
            ) s
            WHERE s.purchase_request_id = pr.id
            SQL);
        $this->addSql('CREATE INDEX idx_purchase_request_total_amount ON purchase_request (total_amount_kopecks)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_purchase_request_total_amount');
        $this->addSql('ALTER TABLE purchase_request DROP total_amount_kopecks');
    }
}
