<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Отметка задачи: рецензирование договора или утверждение чужих рецензий.
 * Пусто — задача договор не разбирает.
 */
final class Version20260928130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contract_review on purchase route tasks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_route_template_task ADD contract_review VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_approval_task ADD contract_review VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_route_template_task DROP contract_review');
        $this->addSql('ALTER TABLE purchase_approval_task DROP contract_review');
    }
}
