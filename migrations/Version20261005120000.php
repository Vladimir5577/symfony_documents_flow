<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Закупки: цвет счёта, привязка позиции к договору и счёту, отметка «есть на складе»';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_request_file ADD color VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_request_item ADD in_stock BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE purchase_request_item ADD contract_file_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_request_item ADD invoice_file_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_request_item ADD CONSTRAINT FK_A4A6F5449B9A04A4 FOREIGN KEY (contract_file_id) REFERENCES purchase_request_file (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE purchase_request_item ADD CONSTRAINT FK_A4A6F54461124FBF FOREIGN KEY (invoice_file_id) REFERENCES purchase_request_file (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A4A6F5449B9A04A4 ON purchase_request_item (contract_file_id)');
        $this->addSql('CREATE INDEX IDX_A4A6F54461124FBF ON purchase_request_item (invoice_file_id)');
        // Единственный договор заявки — договор всех её позиций: так его читает новый код.
        $this->addSql(<<<'SQL'
            UPDATE purchase_request_item i SET contract_file_id = f.id
            FROM purchase_request_file f
            WHERE f.purchase_request_id = i.purchase_request_id AND f.type = 'CONTRACT'
              AND (SELECT COUNT(*) FROM purchase_request_file c
                   WHERE c.purchase_request_id = i.purchase_request_id AND c.type = 'CONTRACT') = 1
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_request_item DROP CONSTRAINT FK_A4A6F5449B9A04A4');
        $this->addSql('ALTER TABLE purchase_request_item DROP CONSTRAINT FK_A4A6F54461124FBF');
        $this->addSql('DROP INDEX IDX_A4A6F5449B9A04A4');
        $this->addSql('DROP INDEX IDX_A4A6F54461124FBF');
        $this->addSql('ALTER TABLE purchase_request_item DROP in_stock');
        $this->addSql('ALTER TABLE purchase_request_item DROP contract_file_id');
        $this->addSql('ALTER TABLE purchase_request_item DROP invoice_file_id');
        $this->addSql('ALTER TABLE purchase_request_file DROP color');
    }
}
