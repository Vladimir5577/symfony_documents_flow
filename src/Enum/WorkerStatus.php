<?php

declare(strict_types=1);

namespace App\Enum;

enum WorkerStatus: string
{
    case AT_WORK = 'AT_WORK';                    // Работа
    case ANNUAL_LEAVE = 'ANNUAL_LEAVE';          // Отпуск основной
    case UNPAID_LEAVE = 'UNPAID_LEAVE';          // Отпуск неоплачиваемый по разрешению работодателя
    case MATERNITY_LEAVE = 'MATERNITY_LEAVE';    // Отпуск по беременности и родам
    case PARENTAL_LEAVE = 'PARENTAL_LEAVE';      // Отпуск по уходу за ребенком
    case ON_BUSINESS_TRIP = 'ON_BUSINESS_TRIP';  // В командировке
    case SICK_LEAVE = 'SICK_LEAVE';             // Болезнь
    case REMOTE = 'REMOTE';                      // Удалённая работа
    case DAY_OFF = 'DAY_OFF';                    // Выходной / отгул
    case ON_DUTY = 'ON_DUTY';                    // Дежурство
    case UNAVAILABLE = 'UNAVAILABLE';            // Не на связи
    case CONTRACT_SUSPENDED = 'CONTRACT_SUSPENDED'; // Трудовой договор приостановлен
    case UNEXCUSED_ABSENCE = 'UNEXCUSED_ABSENCE';   // Прогул
    case EDUCATIONAL_PAID_LEAVE = 'EDUCATIONAL_PAID_LEAVE'; // Отпуск учебный оплачиваемый

    public function getLabel(): string
    {
        return match ($this) {
            self::AT_WORK => 'Работа',
            self::ANNUAL_LEAVE => 'Отпуск основной',
            self::UNPAID_LEAVE => 'Отпуск неоплачиваемый по разрешению работодателя',
            self::MATERNITY_LEAVE => 'Отпуск по беременности и родам',
            self::PARENTAL_LEAVE => 'Отпуск по уходу за ребенком',
            self::ON_BUSINESS_TRIP => 'В командировке',
            self::SICK_LEAVE => 'Болезнь',
            self::REMOTE => 'Удалённая работа',
            self::DAY_OFF => 'Выходной / отгул',
            self::ON_DUTY => 'Дежурство',
            self::UNAVAILABLE => 'Не на связи',
            self::CONTRACT_SUSPENDED => 'Трудовой договор приостановлен',
            self::UNEXCUSED_ABSENCE => 'Прогул',
            self::EDUCATIONAL_PAID_LEAVE => 'Отпуск учебный оплачиваемый',
        };
    }

    /**
     * Статусы, при которых сотрудник доступен для назначения документов.
     * @return list<WorkerStatus>
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
