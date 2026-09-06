<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

/** Отказ смены состава участников; errorCode — константа SpaApiError. */
final class DocumentRecipientsException extends \DomainException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
