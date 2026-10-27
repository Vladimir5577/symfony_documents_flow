<?php

declare(strict_types=1);

namespace App\Service\OnlyOffice;

/**
 * Ключ сессии Document Server.
 *
 * После успешной записи (callback status 6) тот же ключ нельзя открыть заново:
 * сервер отвечает «Версия изменилась». Пока в сессии ещё есть редакторы, ключ
 * общий — иначе вторая вкладка получит 403 на «Сохранить».
 */
final class EditorSessionKey
{
    /** Опоздавший status 2 несохранённой сессии не стирает ключ новому входу. */
    public const KEEP_SECONDS = 20;

    /**
     * Живую сессию не рвём. Свежий несохранённый ключ тоже оставляем: вторая вкладка
     * должна успеть войти. Простаивающий и уже записанный ключ Document Server
     * отвечает «Версия изменилась», поэтому его заменяем.
     *
     * @param array{key: string, at: int, saved: bool}|null $stored
     * @param callable(): string $newKey
     */
    public static function next(?array $stored, bool $join, bool $busy, callable $newKey): string
    {
        if ($stored === null || !$join) {
            return $newKey();
        }
        if ($busy || (empty($stored['saved']) && $stored['at'] > time() - self::KEEP_SECONDS)) {
            return $stored['key'];
        }

        return $newKey();
    }

    /** Редактор просит новую версию вместо диалога «Версия изменилась». */
    public static function wantsFresh(string $body): bool
    {
        $payload = json_decode($body, true);

        return is_array($payload) && ($payload['refresh'] ?? false) === true;
    }

    /**
     * @param array{key: string, at: int, saved: bool}|null $stored
     */
    public static function forget(?array $stored, string $key, int $now): bool
    {
        if ($stored === null || !hash_equals($stored['key'], $key)) {
            return false;
        }
        if (!empty($stored['saved'])) {
            return true;
        }

        return $stored['at'] <= $now - self::KEEP_SECONDS;
    }

    /**
     * Ответ команды info. Сеть недоступна — считаем, что сессия жива, ключ не рвём.
     *
     * @param array<string, mixed> $info
     */
    public static function busy(array $info): bool
    {
        if (($info['unreachable'] ?? false) === true) {
            return true;
        }
        if ((int) ($info['error'] ?? 1) !== 0) {
            return false;
        }
        $users = $info['users'] ?? [];

        return is_array($users) && $users !== [];
    }
}
