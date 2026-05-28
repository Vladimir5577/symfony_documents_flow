<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260528180134 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analytics_boards ALTER category DROP DEFAULT');
        $this->addSql('ALTER TABLE kanban_board ADD position DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE kanban_card ADD archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE kanban_card ADD archived_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE kanban_card ADD CONSTRAINT FK_B214048077BE2925 FOREIGN KEY (archived_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_B214048077BE2925 ON kanban_card (archived_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analytics_boards ALTER category SET DEFAULT \'other\'');
        $this->addSql('ALTER TABLE kanban_board DROP position');
        $this->addSql('ALTER TABLE kanban_card DROP CONSTRAINT FK_B214048077BE2925');
        $this->addSql('DROP INDEX IDX_B214048077BE2925');
        $this->addSql('ALTER TABLE kanban_card DROP archived_at');
        $this->addSql('ALTER TABLE kanban_card DROP archived_by_id');
    }
}
