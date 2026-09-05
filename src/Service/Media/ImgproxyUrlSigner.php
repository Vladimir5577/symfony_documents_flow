<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * Подпись URL imgproxy (BE-03 / SEC-05, FE-01).
 *
 * Код раньше жёстко писал `/unsafe/` в путь, поэтому включение IMGPROXY_KEY/SALT
 * на сервере молча ломало все аватары и картинки закупок — конфигурационный
 * фикс был неприменим. Теперь при заданных ключе и соли путь подписывается
 * (base64url(HMAC-SHA256(salt ‖ path, key))), без них — прежний `/unsafe/`.
 *
 * Порядок выката: сначала подпись в коде (imgproxy при ALLOW_UNSAFE_URL=1
 * принимает и подписанные URL), потом IMGPROXY_ALLOW_UNSAFE_URL=0 — окна с
 * битыми картинками не будет. Тот же алгоритм — в go_kanban_service
 * (internal/media/imgproxy.go); ключ и соль у обоих сервисов одни.
 */
final class ImgproxyUrlSigner
{
    private readonly ?string $key;
    private readonly ?string $salt;

    public function __construct(?string $keyHex, ?string $saltHex)
    {
        $key = $keyHex !== null && $keyHex !== '' ? @hex2bin($keyHex) : false;
        $salt = $saltHex !== null && $saltHex !== '' ? @hex2bin($saltHex) : false;

        $this->key = $key !== false ? $key : null;
        $this->salt = $salt !== false ? $salt : null;
    }

    public function isEnabled(): bool
    {
        return $this->key !== null && $this->salt !== null;
    }

    /**
     * @param string $path путь обработки, начиная со слэша: "/rs:fit:96:96/plain/s3://bucket/key"
     *
     * @return string "/{signature}{path}" либо "/unsafe{path}"
     */
    public function sign(string $path): string
    {
        if ($this->key === null || $this->salt === null) {
            return '/unsafe' . $path;
        }

        $digest = hash_hmac('sha256', $this->salt . $path, $this->key, true);
        $signature = rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');

        return '/' . $signature . $path;
    }
}
