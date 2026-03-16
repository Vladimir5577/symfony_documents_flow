<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260316104538 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_message ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE chat_message ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE chat_room ADD name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE chat_room ADD created_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE chat_room ADD CONSTRAINT FK_D403CCDAB03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_D403CCDAB03A8386 ON chat_room (created_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_message DROP updated_at');
        $this->addSql('ALTER TABLE chat_message DROP deleted_at');
        $this->addSql('ALTER TABLE chat_room DROP CONSTRAINT FK_D403CCDAB03A8386');
        $this->addSql('DROP INDEX IDX_D403CCDAB03A8386');
        $this->addSql('ALTER TABLE chat_room DROP name');
        $this->addSql('ALTER TABLE chat_room DROP created_by_id');
    }
}
