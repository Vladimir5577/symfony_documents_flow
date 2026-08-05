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
    case CATEGORY_CHANGED = 'category_changed';
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
            self::UPD_CHANGED => 'Изменён документ поступления',
        };
    }
}
