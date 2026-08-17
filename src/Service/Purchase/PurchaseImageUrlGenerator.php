<?php

declare(strict_types=1);

namespace App\Service\Purchase;

/**
 * Ссылки на картинки справочника закупок — всегда через imgproxy: он режет размер
 * и отдаёт WebP. Эндпоинта «скачать оригинал» для них нет намеренно.
 *
 * Размер один и маленький: картинки показываются превьюшками в 28–32 пикселя,
 * и гонять под них четырёхсотпиксельный рендер — впустую греть imgproxy и сеть.
 * Понадобится крупный показ — здесь появится второй метод, а не правка этого.
 */
final class PurchaseImageUrlGenerator
{
    public function __construct(
        private readonly string $imgproxyCacheBaseUrl,
        private readonly string $minioPurchaseBucket,
    ) {
    }

    public function getImageUrl(?string $storageKey): ?string
    {
        if ($storageKey === null || $storageKey === '') {
            return null;
        }

        return sprintf(
            '%s/unsafe/rs:fit:96:96/plain/s3://%s/%s',
            rtrim($this->imgproxyCacheBaseUrl, '/'),
            $this->minioPurchaseBucket,
            $storageKey,
        );
    }
}
