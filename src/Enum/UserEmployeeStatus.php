<?php

declare(strict_types=1);

namespace App\Enum;

enum UserEmployeeStatus: string
{
    case AT_WORK = 'AT_WORK';                    // На работе
    case ON_LEAVE = 'ON_LEAVE';                  // В отпуске
    case ON_BUSINESS_TRIP = 'ON_BUSINESS_TRIP';  // В командировке
    case SICK_LEAVE = 'SICK_LEAVE';             // На больничном
    case REMOTE = 'REMOTE';                      // Удалённая работа
    case DAY_OFF = 'DAY_OFF';                    // Выходной / отгул
    case ON_DUTY = 'ON_DUTY';                    // Дежурство
    case UNAVAILABLE = 'UNAVAILABLE';            // Не на связи

    public function getLabel(): string
    {
        return match ($this) {
            self::AT_WORK => 'На работе',
            self::ON_LEAVE => 'В отпуске',
            self::ON_BUSINESS_TRIP => 'В командировке',
            self::SICK_LEAVE => 'На больничном',
            self::REMOTE => 'Удалённая работа',
            self::DAY_OFF => 'Выходной / отгул',
            self::ON_DUTY => 'Дежурство',
            self::UNAVAILABLE => 'Не на связи',
        };
    }

    /**
     * Статусы, при которых сотрудник доступен для назначения документов.
     * @return list<UserEmployeeStatus>
     */
    public static function getAvailableForAssignment(): array
    {
        return [
            self::AT_WORK,
            self::REMOTE,
            self::ON_DUTY,
        ];
    }

    public function isAvailableForAssignment(): bool
    {
        return in_array($this, self::getAvailableForAssignment(), true);
    }

    /**
     * Варианты для выбора в формах.
     * @return array<string, string> [value => label]
     */
    public static function getChoices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->value] = $case->getLabel();
        }
        return $choices;
    }
}
