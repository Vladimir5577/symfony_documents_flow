<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

enum CredentialKind: string
{
    case ADMIN = 'admin';
    case WEB = 'web';
    case SSH = 'ssh';
    case SNMP = 'snmp';
    case OTHER = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::ADMIN => 'Администратор',
            self::WEB => 'Веб-интерфейс',
            self::SSH => 'SSH',
            self::SNMP => 'SNMP',
            self::OTHER => 'Другое',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function getChoices(): array
    {
        return array_column(
            array_map(static fn (self $case): array => [$case->value, $case->getLabel()], self::cases()),
            1,
            0,
        );
    }
}
