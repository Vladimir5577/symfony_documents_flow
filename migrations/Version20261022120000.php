<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'document_comment_file.editor_revision for OnlyOffice document key';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_comment_file ADD editor_revision INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_comment_file DROP editor_revision');
    }
}
