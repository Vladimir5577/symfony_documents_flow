<?php

declare(strict_types=1);

namespace App\Enum;

enum DocumentStatus: string
{
    case DRAFT = 'DRAFT';                    // Черновик
    case NEW = 'NEW';                        // Новый
    case IN_REVIEW = 'IN_REVIEW';            // На рассмотрении
    case PENDING_APPROVAL = 'PENDING_APPROVAL'; // На согласовании
    case APPROVED = 'APPROVED';              // Утвержден
    case REJECTED = 'REJECTED';              // Отклонен
    case IN_PROGRESS = 'IN_PROGRESS';        // В работе
    case COMPLETED = 'COMPLETED';            // Завершен
    case CANCELLED = 'CANCELLED';            // Отменен

    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::NEW => 'Новый',
            self::IN_REVIEW => 'На рассмотрении',
            self::PENDING_APPROVAL => 'На согласовании',
            self::APPROVED => 'Утвержден',
            self::REJECTED => 'Отклонен',
            self::IN_PROGRESS => 'В работе',
            self::COMPLETED => 'Завершен',
            self::CANCELLED => 'Отменен',
        };
    }

    /**
     * Получить все статусы в виде массива для использования в формах
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

    /**
     * Получить статусы для создания документа (только Черновик и Новый)
     * @return array<string, string> [value => label]
     */
    public static function getCreationChoices(): array
    {
        return [
            self::DRAFT->value => self::DRAFT->getLabel(),
            self::NEW->value => self::NEW->getLabel(),
        ];
    }

    /**
     * Статусы, которые получатель документа может выставить себе.
     * @return list<DocumentStatus>
     */
    public static function getReceiverAllowedStatuses(): array
    {
        return [
            self::IN_PROGRESS,
            self::IN_REVIEW,
            self::APPROVED,
            self::REJECTED,
        ];
    }
}
