<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111202000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store Block Blast campaign progress per Pulse account.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE rest_block_blast_campaign (
            user_id INT PRIMARY KEY REFERENCES "user" (id) ON DELETE CASCADE,
            completed_level SMALLINT NOT NULL CHECK (completed_level BETWEEN 1 AND 100)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rest_block_blast_campaign');
    }
}
