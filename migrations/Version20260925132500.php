<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Пустые purchase_approval_step и purchase_setting остались после перехода
 * на этапы: сущностей больше нет, другие таблицы на них не ссылаются.
 *
 * editor_revision входит в ключ документа OnlyOffice и растёт, когда итог
 * сессии лёг в хранилище. Существующим файлам хватает единицы — общих сессий
 * по ним ещё не было.
 *
 * contract_review — рецензирование договора или утверждение чужих рецензий.
 * Пусто — задача договор не разбирает. contract_review_passed — отметка, что
 * этот шаг по задаче уже пройден.
 */
final class Version20260925132500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop unused purchase tables; add editor revision and contract review flags';
    }

    public function up(Schema $schema): void
    {
        // IF EXISTS: на стенде этих таблиц уже не было, и миграция падала.
        $this->addSql('DROP TABLE IF EXISTS purchase_approval_step');
        $this->addSql('DROP TABLE IF EXISTS purchase_setting');

        $this->addSql('ALTER TABLE purchase_request_file ADD editor_revision INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE purchase_route_template_task ADD contract_review VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_approval_task ADD contract_review VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_approval_task ADD contract_review_passed BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_approval_task DROP contract_review_passed');
        $this->addSql('ALTER TABLE purchase_approval_task DROP contract_review');
        $this->addSql('ALTER TABLE purchase_route_template_task DROP contract_review');
        $this->addSql('ALTER TABLE purchase_request_file DROP editor_revision');
    }
}
