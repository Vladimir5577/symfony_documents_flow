<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «Сумма до» у маршрута: заявки дешевле порога идут ускоренной процедурой.
 * У существующих маршрутов порога нет — всё работает как раньше.
 */
final class Version20260925210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add purchase_route_template.max_amount_kopecks for amount-based fast track routes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_route_template ADD max_amount_kopecks BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_route_template DROP max_amount_kopecks');
    }
}
