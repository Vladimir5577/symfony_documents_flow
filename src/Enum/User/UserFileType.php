<?php

namespace App\Enum\User;

enum UserFileType: string
{
    case TEMPLATE = 'template';
    case DRAFT = 'draft';
    case IMPORTANT = 'important';
    case UNIMPORTANT = 'unimportant';
    case CONTRACT = 'contract';
    case CERTIFICATE = 'certificate';
    case ORDER = 'order';
    case ACT = 'act';
    case REPORT = 'report';
    case INSTRUCTION = 'instruction';
    case REFERENCE = 'reference';
    case APPLICATION = 'application';
    case PROTOCOL = 'protocol';
    case LETTER = 'letter';
    case REGULATION = 'regulation';
    case PRESENTATION = 'presentation';
    case PERSONAL = 'personal';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TEMPLATE => 'Шаблон',
            self::DRAFT => 'Черновик',
            self::IMPORTANT => 'Важный документ',
            self::UNIMPORTANT => 'Неважный документ',
            self::CONTRACT => 'Договор',
            self::CERTIFICATE => 'Справка / сертификат',
            self::ORDER => 'Приказ',
            self::ACT => 'Акт',
            self::REPORT => 'Отчёт',
            self::INSTRUCTION => 'Инструкция',
            self::REFERENCE => 'Справка',
            self::APPLICATION => 'Заявление',
            self::PROTOCOL => 'Протокол',
            self::LETTER => 'Письмо',
            self::REGULATION => 'Положение',
            self::PRESENTATION => 'Презентация',
            self::PERSONAL => 'Личные',
            self::OTHER => 'Разное',
        };
    }
}
