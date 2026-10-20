<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020110925 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE purchase_approval_stage ADD allows_in_stock BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE purchase_approval_stage ADD allows_exclude BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER INDEX idx_purchase_request_progress_status RENAME TO IDX_204D45E6CAE934CB');
        $this->addSql('ALTER INDEX idx_purchase_request_contract_request RENAME TO IDX_CD6E485E4E4DEF6F');
        $this->addSql('ALTER INDEX idx_purchase_request_contract_contract RENAME TO IDX_CD6E485E5ECBF804');
        $this->addSql('ALTER INDEX idx_purchase_file_contract RENAME TO IDX_3722E64A9B9A04A4');
        $this->addSql('ALTER INDEX idx_purchase_file_source RENAME TO IDX_3722E64ADA14C104');
        $this->addSql('ALTER INDEX idx_purchase_file_catalog RENAME TO IDX_3722E64A5ECBF804');
        $this->addSql('ALTER INDEX idx_purchase_item_contract RENAME TO IDX_A4A6F5442576E0FD');
        $this->addSql('ALTER TABLE purchase_route_template_stage ADD allows_in_stock BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE purchase_route_template_stage ADD allows_exclude BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE purchase_approval_stage DROP allows_in_stock');
        $this->addSql('ALTER TABLE purchase_approval_stage DROP allows_exclude');
        $this->addSql('ALTER INDEX idx_204d45e6cae934cb RENAME TO idx_purchase_request_progress_status');
        $this->addSql('ALTER INDEX idx_cd6e485e5ecbf804 RENAME TO idx_purchase_request_contract_contract');
        $this->addSql('ALTER INDEX idx_cd6e485e4e4def6f RENAME TO idx_purchase_request_contract_request');
        $this->addSql('ALTER INDEX idx_3722e64a9b9a04a4 RENAME TO idx_purchase_file_contract');
        $this->addSql('ALTER INDEX idx_3722e64a5ecbf804 RENAME TO idx_purchase_file_catalog');
        $this->addSql('ALTER INDEX idx_3722e64ada14c104 RENAME TO idx_purchase_file_source');
        $this->addSql('ALTER INDEX idx_a4a6f5442576e0fd RENAME TO idx_purchase_item_contract');
        $this->addSql('ALTER TABLE purchase_route_template_stage DROP allows_in_stock');
        $this->addSql('ALTER TABLE purchase_route_template_stage DROP allows_exclude');
    }
}
