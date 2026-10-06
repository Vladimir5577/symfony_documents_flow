<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261006060829 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE purchase_approval_task ADD contract_reviewed_file_ids JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE purchase_approval_task DROP contract_review_passed');
        $this->addSql('ALTER TABLE purchase_request_file ADD color VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_request_item ADD in_stock BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE purchase_request_item ADD contract_file_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_request_item ADD invoice_file_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_request_item ADD CONSTRAINT FK_A4A6F5449B9A04A4 FOREIGN KEY (contract_file_id) REFERENCES purchase_request_file (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE purchase_request_item ADD CONSTRAINT FK_A4A6F54461124FBF FOREIGN KEY (invoice_file_id) REFERENCES purchase_request_file (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A4A6F5449B9A04A4 ON purchase_request_item (contract_file_id)');
        $this->addSql('CREATE INDEX IDX_A4A6F54461124FBF ON purchase_request_item (invoice_file_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE purchase_approval_task ADD contract_review_passed BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE purchase_approval_task DROP contract_reviewed_file_ids');
        $this->addSql('ALTER TABLE purchase_request_file DROP color');
        $this->addSql('ALTER TABLE purchase_request_item DROP CONSTRAINT FK_A4A6F5449B9A04A4');
        $this->addSql('ALTER TABLE purchase_request_item DROP CONSTRAINT FK_A4A6F54461124FBF');
        $this->addSql('DROP INDEX IDX_A4A6F5449B9A04A4');
        $this->addSql('DROP INDEX IDX_A4A6F54461124FBF');
        $this->addSql('ALTER TABLE purchase_request_item DROP in_stock');
        $this->addSql('ALTER TABLE purchase_request_item DROP contract_file_id');
        $this->addSql('ALTER TABLE purchase_request_item DROP invoice_file_id');
    }
}
