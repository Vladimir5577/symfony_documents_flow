<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Service\ImgproxyUrlSigner;

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
        private readonly ImgproxyUrlSigner $imgproxyUrlSigner,
    ) {
    }

    public function getImageUrl(?string $storageKey): ?string
    {
        if ($storageKey === null || $storageKey === '') {
            return null;
        }

        $source = sprintf('s3://%s/%s', $this->minioPurchaseBucket, $storageKey);

        return rtrim($this->imgproxyCacheBaseUrl, '/') . '/' . $this->imgproxyUrlSigner->signSource('rs:fit:96:96', $source);
    }
}
