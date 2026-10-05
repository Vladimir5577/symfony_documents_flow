<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Закупки: отметка рецензии — по каждому договору, а не одна на задачу';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_approval_task ADD contract_reviewed_file_ids JSON DEFAULT \'[]\' NOT NULL');
        // Прежняя отметка значила «договор заявки отрецензирован»: переносим её на договоры, что есть сейчас.
        $this->addSql(<<<'SQL'
            UPDATE purchase_approval_task t SET contract_reviewed_file_ids = (
                SELECT COALESCE(json_agg(f.id), '[]'::json)
                FROM purchase_approval_stage s
                JOIN purchase_request_file f ON f.purchase_request_id = s.purchase_request_id AND f.type = 'CONTRACT'
                WHERE s.id = t.stage_id
            )
            WHERE t.contract_review_passed
            SQL);
        $this->addSql('ALTER TABLE purchase_approval_task DROP contract_review_passed');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_approval_task ADD contract_review_passed BOOLEAN DEFAULT false NOT NULL');
        $this->addSql("UPDATE purchase_approval_task SET contract_review_passed = true WHERE contract_reviewed_file_ids::text <> '[]'");
        $this->addSql('ALTER TABLE purchase_approval_task DROP contract_reviewed_file_ids');
    }
}
