<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Пустые purchase_approval_step и purchase_setting остались после перехода
 * на этапы: сущностей больше нет, другие таблицы на них не ссылаются.
 */
final class Version20260925132500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop unused purchase_approval_step and purchase_setting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE purchase_approval_step');
        $this->addSql('DROP TABLE purchase_setting');
    }

    public function down(Schema $schema): void
    {
    }
}
