<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist online long narde matches and waiting rooms.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE rest_narde_match (
            code VARCHAR(8) NOT NULL PRIMARY KEY,
            host_id INT NOT NULL REFERENCES "user" (id) ON DELETE CASCADE,
            guest_id INT REFERENCES "user" (id) ON DELETE CASCADE,
            mode VARCHAR(10) NOT NULL,
            status VARCHAR(10) NOT NULL,
            state JSONB NOT NULL,
            version INT NOT NULL,
            updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL
        )');
        $this->addSql('CREATE INDEX rest_narde_match_host_idx ON rest_narde_match (host_id, updated_at)');
        $this->addSql('CREATE INDEX rest_narde_match_guest_idx ON rest_narde_match (guest_id, updated_at)');
        $this->addSql('CREATE INDEX rest_narde_match_queue_idx ON rest_narde_match (mode, status, updated_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rest_narde_match');
    }
}
