<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'document_comment_file.edited_at when OnlyOffice save rewrites the file';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_comment_file ADD edited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_comment_file DROP edited_at');
    }
}
