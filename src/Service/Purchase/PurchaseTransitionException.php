<?php

declare(strict_types=1);

namespace App\Service\Purchase;

/**
 * Недопустимый переход статуса заявки. Код ошибки — константа из SpaApiError.
 */
final class PurchaseTransitionException extends \DomainException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
