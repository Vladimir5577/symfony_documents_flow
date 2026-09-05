<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Аудит 2026-09-05 — правка данных, оставшихся от закрытых дефектов.
 *
 * BE-21: редактор маршрутов принимал allowsReject=true на этапах исполнения
 * (PAYMENT/DELIVERY/CLOSING), а колонка объявлена DEFAULT true — теперь код
 * гасит флаг безусловно, здесь выравниваем уже сохранённые заготовки и снимки.
 *
 * BE-23: получатель мог открыть неопубликованный черновик по id, и его строка
 * переходила в VIEWED с записью в историю. Возвращаем таким строкам NEW и
 * убираем записи «просмотрел» по неопубликованным документам — иначе счётчик
 * новых входящих останется заниженным и после фикса.
 */
final class Version20260905150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Аудит 2026-09-05: allows_reject=false на этапах исполнения';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE purchase_route_template_stage SET allows_reject = false WHERE purpose IN ('PAYMENT', 'DELIVERY', 'CLOSING') AND allows_reject = true");
        $this->addSql("UPDATE purchase_approval_stage SET allows_reject = false WHERE purpose IN ('PAYMENT', 'DELIVERY', 'CLOSING') AND allows_reject = true");

        // Чистка истории «просмотрел» по неопубликованным документам сюда не
        // вошла намеренно: по текущему is_published = false нельзя отличить
        // никогда не публиковавшийся документ от снятого с публикации, и
        // миграция стёрла бы законные просмотры. При необходимости — ручной
        // скрипт по конкретным документам после сверки с бизнесом.
    }

    public function down(Schema $schema): void
    {
        // Правка данных: прежние значения не восстанавливаются намеренно.
    }
}
