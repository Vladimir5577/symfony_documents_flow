<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Service\Media\ImgproxyUrlSigner;

/**
 * Ссылки на картинки справочника закупок — всегда через imgproxy: он режет размер
 * и отдаёт WebP. Эндпоинта «скачать оригинал» для них нет намеренно.
 *
 * Размер один и маленький: картинки показываются превьюшками в 28–32 пикселя,
 * и гонять под них четырёхсотпиксельный рендер — впустую греть imgproxy и сеть.
 * Понадобится крупный показ — здесь появится второй метод, а не правка этого.
 *
 * Путь подписывается ImgproxyUrlSigner, если заданы IMGPROXY_KEY/SALT (BE-03).
 */
final class PurchaseImageUrlGenerator
{
    public function __construct(
        private readonly string $imgproxyCacheBaseUrl,
        private readonly string $minioPurchaseBucket,
        private readonly ImgproxyUrlSigner $signer,
    ) {
    }

    public function getImageUrl(?string $storageKey): ?string
    {
        if ($storageKey === null || $storageKey === '') {
            return null;
        }

        $path = sprintf('/rs:fit:96:96/plain/s3://%s/%s', $this->minioPurchaseBucket, $storageKey);

        return rtrim($this->imgproxyCacheBaseUrl, '/') . $this->signer->sign($path);
    }
}
