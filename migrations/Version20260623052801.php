<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260623052801 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analytics_reports ADD approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_reports ADD approved_by INT DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_reports ADD CONSTRAINT FK_5BBD2BBB4EA3CB3D FOREIGN KEY (approved_by) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_5BBD2BBB4EA3CB3D ON analytics_reports (approved_by)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analytics_reports DROP CONSTRAINT FK_5BBD2BBB4EA3CB3D');
        $this->addSql('DROP INDEX IDX_5BBD2BBB4EA3CB3D');
        $this->addSql('ALTER TABLE analytics_reports DROP approved_at');
        $this->addSql('ALTER TABLE analytics_reports DROP approved_by');
    }
}
