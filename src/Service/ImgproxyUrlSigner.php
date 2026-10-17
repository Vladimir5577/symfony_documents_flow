<?php

declare(strict_types=1);

namespace App\Service;

final class ImgproxyUrlSigner
{
    public function __construct(
        private readonly string $imgproxyKey,
        private readonly string $imgproxySalt,
    ) {
    }

    /**
     * Источник кодируется в base64: в «plain/s3://…» двойной слэш склеивает
     * merge_slashes nginx-шлюза, и подпись разошлась бы с путём, который получит imgproxy.
     */
    public function signSource(string $options, string $source): string
    {
        return $this->sign('/' . $options . '/' . rtrim(strtr(base64_encode($source), '+/', '-_'), '='));
    }

    public function sign(string $path): string
    {
        if ($this->imgproxyKey === '' || $this->imgproxySalt === '') {
            return 'unsafe' . $path;
        }

        if (strlen($this->imgproxyKey) % 2 !== 0 || !ctype_xdigit($this->imgproxyKey)
            || strlen($this->imgproxySalt) % 2 !== 0 || !ctype_xdigit($this->imgproxySalt)) {
            throw new \InvalidArgumentException('IMGPROXY_KEY и IMGPROXY_SALT должны быть hex-строками.');
        }

        $signature = hash_hmac('sha256', hex2bin($this->imgproxySalt) . $path, hex2bin($this->imgproxyKey), true);

        return rtrim(strtr(base64_encode($signature), '+/', '-_'), '=') . $path;
    }
}
