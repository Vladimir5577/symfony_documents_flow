<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

/**
 * Что именно сделал человек — код события для ленты истории.
 *
 * Раньше в историю попадали только смены статуса, а всё согласование идёт
 * внутри одного ON_APPROVAL: подписи лежали на шагах и исчезали вместе с ними
 * при повторной подаче. Теперь каждое действие пишется отдельной строкой, и
 * «кто, что, когда» читается из одной таблицы.
 *
 * Код нужен, чтобы фронт строил ленту по нему, а не разбирал русский текст
 * комментария.
 */
enum PurchaseHistoryAction: string
{
    case CREATED = 'CREATED';
    case SUBMITTED = 'SUBMITTED';
    case STEP_APPROVED = 'STEP_APPROVED';
    case STEP_REJECTED = 'STEP_REJECTED';
    case STEP_REVOKED = 'STEP_REVOKED';
    case RETURNED_TO_DEPARTMENT = 'RETURNED_TO_DEPARTMENT';
    case APPROVERS_ASSIGNED = 'APPROVERS_ASSIGNED';
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
            self::STEP_APPROVED => 'Шаг согласован',
            self::STEP_REJECTED => 'Возвращена автору',
            self::STEP_REVOKED => 'Подпись снята',
            self::RETURNED_TO_DEPARTMENT => 'Возвращена в отдел закупок',
            self::APPROVERS_ASSIGNED => 'Назначены профильные замы',
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
