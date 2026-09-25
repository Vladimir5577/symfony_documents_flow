<?php

declare(strict_types=1);

namespace App\Service\OnlyOffice;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Подпись обмена с OnlyOffice Document Server: HS256 на общем секрете
 * (JWT_SECRET сервера документов = ONLYOFFICE_JWT_SECRET здесь).
 *
 * Пока секрет не задан, подписи нет — так Document Server работает сейчас
 * (JWT_ENABLED=false). Для общего документа она нужна всерьёз: без неё любой,
 * кому договор открыт на просмотр, может поправить конфиг в браузере, войти в
 * сессию правщиком под чужим отделом, и его правки сохранятся вместе со всеми.
 *
 * Своя реализация вместо библиотеки: lcobucci/jwt требует ключ от 256 бит, а
 * секрет Document Server такого ограничения не имеет.
 */
final class OnlyOfficeJwt
{
    public function __construct(
        #[Autowire('%onlyoffice_jwt_secret%')]
        private readonly ?string $secret,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->secret !== null && $this->secret !== '';
    }

    /** @param array<string, mixed> $payload */
    public function encode(array $payload): string
    {
        $header = self::base64url((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = self::base64url(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $header . '.' . $body . '.' . $this->signature($header . '.' . $body);
    }

    /**
     * Полезная нагрузка токена от Document Server; null — подпись не сошлась,
     * токен просрочен или подпись выключена.
     *
     * @return array<string, mixed>|null
     */
    public function decode(string $token): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$header, $body, $signature] = $parts;

        if (!hash_equals($this->signature($header . '.' . $body), $signature)) {
            return null;
        }
        $head = json_decode(self::base64urlDecode($header), true);
        if (!is_array($head) || ($head['alg'] ?? null) !== 'HS256') {
            return null;
        }
        $payload = json_decode(self::base64urlDecode($body), true);
        if (!is_array($payload)) {
            return null;
        }
        if (isset($payload['exp']) && is_numeric($payload['exp']) && (int) $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    private function signature(string $data): string
    {
        return self::base64url(hash_hmac('sha256', $data, (string) $this->secret, true));
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
