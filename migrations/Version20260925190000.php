<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Редакция вложения для совместной правки в OnlyOffice: входит в ключ
 * документа и растёт, когда итог сессии лёг в хранилище. Существующим
 * файлам хватает единицы — общих сессий по ним ещё не было.
 */
final class Version20260925190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add purchase_request_file.editor_revision for OnlyOffice co-editing keys';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_request_file ADD editor_revision INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_request_file DROP editor_revision');
    }
}
