<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260528080743 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE analytics_boards ADD category VARCHAR(32) DEFAULT 'other'");
        $this->addSql("UPDATE analytics_boards SET category = 'other' WHERE category IS NULL");
        $this->addSql('ALTER TABLE analytics_boards ALTER COLUMN category SET NOT NULL');
        $this->addSql('CREATE INDEX idx_analytics_boards_category ON analytics_boards (category)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_analytics_boards_category');
        $this->addSql('ALTER TABLE analytics_boards DROP category');
    }
}
