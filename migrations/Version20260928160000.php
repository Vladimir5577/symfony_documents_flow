<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Отметка, что рецензирование или утверждение рецензий по задаче пройдено.
 */
final class Version20260928160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contract_review_passed on purchase approval tasks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_approval_task ADD contract_review_passed BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_approval_task DROP contract_review_passed');
    }
}
