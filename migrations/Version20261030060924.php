<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261030060924 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_inventory_category_name');
        $this->addSql('ALTER TABLE inventory_category ADD parent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE inventory_category ADD CONSTRAINT FK_B206A350727ACA70 FOREIGN KEY (parent_id) REFERENCES inventory_category (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_B206A350727ACA70 ON inventory_category (parent_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_inventory_category_parent_name ON inventory_category (parent_id, name)');
        $this->addSql('CREATE UNIQUE INDEX uniq_inventory_category_root_name ON inventory_category (name) WHERE (parent_id IS NULL)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE inventory_category DROP CONSTRAINT FK_B206A350727ACA70');
        $this->addSql('DROP INDEX IDX_B206A350727ACA70');
        $this->addSql('DROP INDEX uniq_inventory_category_parent_name');
        $this->addSql('DROP INDEX uniq_inventory_category_root_name');
        $this->addSql('ALTER TABLE inventory_category DROP parent_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_inventory_category_name ON inventory_category (name)');
    }
}
