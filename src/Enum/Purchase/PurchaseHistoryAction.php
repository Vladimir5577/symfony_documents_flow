<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

/**
 * Что именно сделал человек — код события для ленты истории.
 *
 * В историю попадают не только смены статуса. Согласование целиком идёт внутри
 * одного ON_APPROVAL, а решения лежат на задачах и исчезают вместе с ними при
 * повторной подаче, возврате в закупки и смене маршрута. Поэтому каждое действие
 * пишется отдельной строкой: след того, что подпись была и сгорела, должен
 * пережить сами задачи.
 *
 * Код нужен, чтобы фронт строил ленту по нему, а не разбирал русский текст
 * комментария.
 */
enum PurchaseHistoryAction: string
{
    case CREATED = 'CREATED';
    case SUBMITTED = 'SUBMITTED';
    case TASK_APPROVED = 'TASK_APPROVED';
    case TASK_REJECTED = 'TASK_REJECTED';
    case TASK_REVOKED = 'TASK_REVOKED';
    case RETURNED_TO_DEPARTMENT = 'RETURNED_TO_DEPARTMENT';
    case APPROVERS_ASSIGNED = 'APPROVERS_ASSIGNED';
    /** Маршрут заявки сменили на разборе — снимок собран заново. */
    case ROUTE_CHANGED = 'ROUTE_CHANGED';
    case ITEMS_EDITED = 'ITEMS_EDITED';
    case SOURCING_UPDATED = 'SOURCING_UPDATED';
    case CLASSIFICATION_UPDATED = 'CLASSIFICATION_UPDATED';
    case STATUS_CHANGED = 'STATUS_CHANGED';
    case CANCELLED = 'CANCELLED';
    case PRIORITY_CHANGED = 'PRIORITY_CHANGED';
    case FILE_UPLOADED = 'FILE_UPLOADED';
    case FILE_DELETED = 'FILE_DELETED';

    public function getLabel(): string
    {
        return match ($this) {
            self::CREATED => 'Заявка создана',
            self::SUBMITTED => 'Заявка подана',
            self::TASK_APPROVED => 'Согласовано',
            self::TASK_REJECTED => 'Возвращена автору',
            self::TASK_REVOKED => 'Подпись снята',
            self::RETURNED_TO_DEPARTMENT => 'Возвращена в отдел закупок',
            self::APPROVERS_ASSIGNED => 'Назначены согласанты',
            self::ROUTE_CHANGED => 'Маршрут изменён',
            self::ITEMS_EDITED => 'Состав заявки изменён',
            self::SOURCING_UPDATED => 'Поставщик и цены обновлены',
            self::CLASSIFICATION_UPDATED => 'Классификация изменена',
            self::STATUS_CHANGED => 'Статус изменён',
            self::CANCELLED => 'Заявка отменена',
            self::PRIORITY_CHANGED => 'Приоритет изменён',
            self::FILE_UPLOADED => 'Загружен файл',
            self::FILE_DELETED => 'Удалён файл',
        };
    }
}
