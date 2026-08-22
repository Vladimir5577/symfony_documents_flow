<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

enum ItemHistoryAction: string
{
    case CREATED = 'created';
    case ASSIGNED = 'assigned';
    case UNASSIGNED = 'unassigned';
    case STATUS_CHANGED = 'status_changed';
    case MOVED = 'moved';
    /**
     * Категория переехала на вид товара, поэтому у позиции меняется вид, а не категория.
     * Значение остаётся в enum ради уже записанных строк журнала: без него Doctrine
     * падает на чтении истории. Новых таких записей не пишем.
     */
    case CATEGORY_CHANGED = 'category_changed';
    case NOMENCLATURE_CHANGED = 'nomenclature_changed';
    case UPD_CHANGED = 'upd_changed';

    public function getLabel(): string
    {
        return match ($this) {
            self::CREATED => 'Создан',
            self::ASSIGNED => 'Назначен пользователю',
            self::UNASSIGNED => 'Снят с пользователя',
            self::STATUS_CHANGED => 'Изменён статус',
            self::MOVED => 'Перемещён в другую организацию',
            self::CATEGORY_CHANGED => 'Изменена категория',
            self::NOMENCLATURE_CHANGED => 'Изменён вид товара',
            self::UPD_CHANGED => 'Изменён документ поступления',
        };
    }
}
