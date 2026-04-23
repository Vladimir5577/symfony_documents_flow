<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260423052332 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE kanban_card ADD created_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE kanban_card ADD CONSTRAINT FK_B2140480B03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_B2140480B03A8386 ON kanban_card (created_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE kanban_card DROP CONSTRAINT FK_B2140480B03A8386');
        $this->addSql('DROP INDEX IDX_B2140480B03A8386');
        $this->addSql('ALTER TABLE kanban_card DROP created_by_id');
    }
}
