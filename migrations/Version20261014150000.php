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
        $this->addSql('ALTER TABLE purchase_request_item ADD contract_id INT DEFAULT NULL');
        // Позиции, разложенные по ещё не утверждённому файлу: заводим ему черновик,
        // как ensureDrafts при открытии карточки, иначе привязку не к чему перенести.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE f RECORD; cid INT;
            BEGIN
              FOR f IN
                SELECT DISTINCT pf.id, pf.purchase_request_id, pf.original_name
                FROM purchase_request_file pf
                JOIN purchase_request_item i ON i.contract_file_id = pf.id
                JOIN purchase_request r ON r.id = pf.purchase_request_id
                WHERE pf.type = 'CONTRACT' AND pf.purchase_contract_id IS NULL AND r.created_as <> 'FAST'
              LOOP
                INSERT INTO purchase_contract (status, name, created_at)
                  VALUES ('DRAFT', left(f.original_name, 255), now()) RETURNING id INTO cid;
                UPDATE purchase_request_file SET purchase_contract_id = cid WHERE id = f.id;
                INSERT INTO purchase_request_contract (purchase_request_id, purchase_contract_id)
                  VALUES (f.purchase_request_id, cid);
              END LOOP;
            END $$
            SQL);
        $this->addSql('UPDATE purchase_request_item i SET contract_id = f.purchase_contract_id FROM purchase_request_file f WHERE f.id = i.contract_file_id');
        $this->addSql('ALTER TABLE purchase_request_item DROP CONSTRAINT FK_A4A6F5449B9A04A4');
        $this->addSql('DROP INDEX IDX_A4A6F5449B9A04A4');
        $this->addSql('ALTER TABLE purchase_request_item DROP contract_file_id');
        $this->addSql('ALTER TABLE purchase_request_item ADD CONSTRAINT FK_PURCHASE_ITEM_CONTRACT FOREIGN KEY (contract_id) REFERENCES purchase_contract (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_PURCHASE_ITEM_CONTRACT ON purchase_request_item (contract_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_request_item DROP CONSTRAINT FK_PURCHASE_ITEM_CONTRACT');
        $this->addSql('DROP INDEX IDX_PURCHASE_ITEM_CONTRACT');
        $this->addSql('ALTER TABLE purchase_request_item ADD contract_file_id INT DEFAULT NULL');
        // Обратно — на файл договора: сначала свой файл заявки, иначе файл договора из справочника.
        $this->addSql("UPDATE purchase_request_item i SET contract_file_id = f.id FROM purchase_request_file f WHERE f.purchase_contract_id = i.contract_id AND f.purchase_request_id = i.purchase_request_id AND f.type = 'CONTRACT'");
        $this->addSql("UPDATE purchase_request_item i SET contract_file_id = f.id FROM purchase_request_file f WHERE i.contract_file_id IS NULL AND f.purchase_contract_id = i.contract_id AND f.type = 'CONTRACT'");
        $this->addSql('ALTER TABLE purchase_request_item DROP contract_id');
        // Прежний код не знает статуса DRAFT и считает любой договор файла утверждённым.
        $this->addSql("UPDATE purchase_request_file SET purchase_contract_id = NULL WHERE purchase_contract_id IN (SELECT id FROM purchase_contract WHERE status = 'DRAFT')");
        $this->addSql("DELETE FROM purchase_contract WHERE status = 'DRAFT'");
        $this->addSql('ALTER TABLE purchase_request_item ADD CONSTRAINT FK_A4A6F5449B9A04A4 FOREIGN KEY (contract_file_id) REFERENCES purchase_request_file (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A4A6F5449B9A04A4 ON purchase_request_item (contract_file_id)');
    }
}
