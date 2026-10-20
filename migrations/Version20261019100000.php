<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Быстрая закупка: обе отметки на этапе со счётом.
 * Обычная: обе отметки на этапе с договором. Остальные этапы остаются без них.
 */
final class Version20261019100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'In-stock and exclude marks: fast route invoice stage, standard route contract stage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_route_template_stage ADD allows_in_stock BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE purchase_route_template_stage ADD allows_exclude BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE purchase_approval_stage ADD allows_in_stock BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE purchase_approval_stage ADD allows_exclude BOOLEAN DEFAULT false NOT NULL');

        // Заготовки. Счёт — быстрая, договор — обычная.
        $this->addSql(<<<'SQL'
            UPDATE purchase_route_template_stage s
            SET allows_in_stock = true, allows_exclude = true
            FROM purchase_route_template tpl
            WHERE s.template_id = tpl.id
              AND EXISTS (
                SELECT 1 FROM purchase_route_template_task t
                WHERE t.stage_id = s.id
                  AND (
                    (tpl.allowed_kinds::jsonb ? 'FAST' AND t.requires_file_type = 'INVOICE')
                    OR (tpl.allowed_kinds::jsonb ? 'STANDARD' AND t.requires_file_type = 'CONTRACT')
                  )
              )
            SQL);
        // Уже поданные заявки идут по снимку, не по заготовке.
        $this->addSql(<<<'SQL'
            UPDATE purchase_approval_stage s
            SET allows_in_stock = true, allows_exclude = true
            FROM purchase_request r
            JOIN purchase_route_template tpl ON tpl.id = r.applied_route_template_id
            WHERE s.purchase_request_id = r.id
              AND EXISTS (
                SELECT 1 FROM purchase_approval_task t
                WHERE t.stage_id = s.id
                  AND (
                    (tpl.allowed_kinds::jsonb ? 'FAST' AND t.requires_file_type = 'INVOICE')
                    OR (tpl.allowed_kinds::jsonb ? 'STANDARD' AND t.requires_file_type = 'CONTRACT')
                  )
              )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_route_template_stage DROP allows_in_stock');
        $this->addSql('ALTER TABLE purchase_route_template_stage DROP allows_exclude');
        $this->addSql('ALTER TABLE purchase_approval_stage DROP allows_in_stock');
        $this->addSql('ALTER TABLE purchase_approval_stage DROP allows_exclude');
    }
}
