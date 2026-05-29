<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260527084500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add position for kanban boards and initialize ordering per project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kanban_board ADD position DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql(<<<'SQL'
            UPDATE kanban_board b
            SET position = src.pos
            FROM (
              SELECT id, ROW_NUMBER() OVER (PARTITION BY kanban_project_id ORDER BY id) AS pos
              FROM kanban_board
            ) src
            WHERE src.id = b.id
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kanban_board DROP position');
    }
}

