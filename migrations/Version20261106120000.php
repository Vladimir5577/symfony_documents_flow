<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Ключ MinIO `{groupId}/{hex}.{ext}` длиннее старого имени на диске. */
final class Version20261106120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen file_group_file.stored_name for MinIO object keys';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE file_group_file ALTER stored_name TYPE VARCHAR(255)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE file_group_file ALTER stored_name TYPE VARCHAR(64)');
    }
}
