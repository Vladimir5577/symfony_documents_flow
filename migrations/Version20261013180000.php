<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Закупки: поиск по названию — триграммный GIN по LOWER(title).
 *
 * Список ищет LOWER(title) LIKE '%строка%': B-tree такой шаблон не берёт, и
 * COUNT поиска читал всю таблицу (замер 13.10 на 100k заявок: 243 мс → 2 мс).
 * CONCURRENTLY — чтобы не блокировать запись в заявки на время построения;
 * внутри транзакции так нельзя, поэтому миграция нетранзакционная.
 */
final class Version20261013180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purchase request title search: pg_trgm GIN index on lower(title)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        // Упавший CONCURRENTLY оставляет индекс INVALID: IF NOT EXISTS молча его
        // пропустил бы, и поиск остался бы без индекса. Поэтому сначала сносим.
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_purchase_request_title_trgm');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_purchase_request_title_trgm ON purchase_request USING gin (LOWER(title) gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        // Расширение не удаляем: им могут пользоваться другие индексы.
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_purchase_request_title_trgm');
    }
}
