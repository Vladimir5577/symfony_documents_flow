<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Позиция ссылается на договор справочника, не на файл.
 * Старые id файлов договорами не являются — колонку заменяем, данные не переносим.
 */
final class Version20261014150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purchase item contract_id points at purchase_contract';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_request_item DROP CONSTRAINT FK_A4A6F5449B9A04A4');
        $this->addSql('DROP INDEX IDX_A4A6F5449B9A04A4');
        $this->addSql('ALTER TABLE purchase_request_item DROP contract_file_id');
        $this->addSql('ALTER TABLE purchase_request_item ADD contract_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_request_item ADD CONSTRAINT FK_PURCHASE_ITEM_CONTRACT FOREIGN KEY (contract_id) REFERENCES purchase_contract (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_PURCHASE_ITEM_CONTRACT ON purchase_request_item (contract_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_request_item DROP CONSTRAINT FK_PURCHASE_ITEM_CONTRACT');
        $this->addSql('DROP INDEX IDX_PURCHASE_ITEM_CONTRACT');
        $this->addSql('ALTER TABLE purchase_request_item DROP contract_id');
        $this->addSql('ALTER TABLE purchase_request_item ADD contract_file_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_request_item ADD CONSTRAINT FK_A4A6F5449B9A04A4 FOREIGN KEY (contract_file_id) REFERENCES purchase_request_file (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A4A6F5449B9A04A4 ON purchase_request_item (contract_file_id)');
    }
}
