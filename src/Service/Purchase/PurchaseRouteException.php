<?php

declare(strict_types=1);

namespace App\Service\Purchase;

/**
 * Заготовка маршрута нарушает правила модуля. Код ошибки — константа из SpaApiError.
 */
final class PurchaseRouteException extends \DomainException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
