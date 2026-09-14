<?php

declare(strict_types=1);

namespace App\Enum\Acknowledgment;

/**
 * Отметка сотрудника по документу.
 *
 * Отсутствие статуса (NULL в строке или отсутствие самой строки) означает
 * «назначен, но не отреагировал» — отдельного случая под это в enum нет
 * намеренно: иначе одно и то же состояние кодировалось бы двумя способами.
 */
enum AckStatus: string
{
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case DISAGREED = 'DISAGREED';
    case LATER = 'LATER';

    public function getLabel(): string
    {
        return match ($this) {
            self::ACKNOWLEDGED => 'Ознакомлен',
            self::DISAGREED => 'Ознакомлен, не согласен',
            self::LATER => 'Ознакомлюсь позже',
        };
    }

    /**
     * Закрывает ли отметка обязанность ознакомиться.
     *
     * «Не согласен» — закрывает: человек документ прочитал, а несогласие это
     * его позиция, а не отказ читать. «Позже» не закрывает ничего, иначе кнопка
     * была бы способом убрать документ из своего списка.
     */
    public function isFinal(): bool
    {
        return $this !== self::LATER;
    }
}
