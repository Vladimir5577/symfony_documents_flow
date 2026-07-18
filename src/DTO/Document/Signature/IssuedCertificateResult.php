<?php

declare(strict_types=1);

namespace App\DTO\Document\Signature;

use App\Entity\Document\UserCertificate;

/**
 * Результат выпуска сертификата внутренним УЦ.
 * Бинарник .p12 существует только в этом объекте (в памяти запроса) и отдаётся пользователю один раз.
 */
final readonly class IssuedCertificateResult
{
    public function __construct(
        public string $p12Binary,
        public UserCertificate $certificate,
    ) {
    }
}
