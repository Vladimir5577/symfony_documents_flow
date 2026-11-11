<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Имена индексов file_group_uploader приведены к тем, что генерирует Doctrine
 * по маппингу FileGroup::uploaders. Иначе doctrine:schema:validate красный и
 * migrations:diff предлагает переименование.
 */
final class Version20261111120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename file_group_uploader indexes to Doctrine-generated names';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_file_group_uploader_group RENAME TO IDX_F7843647FE54D947');
        $this->addSql('ALTER INDEX idx_file_group_uploader_user RENAME TO IDX_F7843647A76ED395');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX IDX_F7843647FE54D947 RENAME TO idx_file_group_uploader_group');
        $this->addSql('ALTER INDEX IDX_F7843647A76ED395 RENAME TO idx_file_group_uploader_user');
    }
}
