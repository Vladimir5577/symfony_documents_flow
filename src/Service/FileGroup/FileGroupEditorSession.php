<?php

declare(strict_types=1);

namespace App\Service\FileGroup;

use App\Service\OnlyOffice\EditorSessionKey;
use App\Service\OnlyOffice\OnlyOfficeJwt;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ключ сессии xlsx группы. Правила те же, что у редактора документов:
 * живую сессию не рвём, после записи тот же ключ не открываем заново.
 */
final class FileGroupEditorSession
{
    public function __construct(
        private readonly OnlyOfficeJwt $jwt,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
    ) {
    }

    /**
     * @return array{key: string, join: bool}
     */
    public function open(int $fileId, bool $edit, bool $refresh): array
    {
        $stored = $this->readKey($fileId);
        $join = $edit || $stored !== null;
        $busy = false;
        if (
            !$refresh
            && $join
            && $stored !== null
            && ($stored['saved'] || $stored['at'] <= time() - EditorSessionKey::KEEP_SECONDS)
        ) {
            $busy = EditorSessionKey::busy($this->command($stored['key'], 'info', ''));
        }
        $key = $refresh
            ? $this->newKey($fileId)
            : EditorSessionKey::next($stored, $join, $busy, fn (): string => $this->newKey($fileId));
        if ($edit) {
            $same = $stored !== null && hash_equals($stored['key'], $key);
            $this->rememberKey($fileId, $key, $same && $stored['saved'], $same && !$busy ? $stored['at'] : null);
        }

        return ['key' => $key, 'join' => $join];
    }

    public function isCurrent(int $fileId, string $key): bool
    {
        $stored = $this->readKey($fileId);

        return $stored !== null && hash_equals($stored['key'], $key);
    }

    public function markSaved(int $fileId, string $key): void
    {
        $stored = $this->readKey($fileId);
        if ($stored === null || !hash_equals($stored['key'], $key)) {
            return;
        }
        $this->rememberKey($fileId, $key, true);
    }

    public function dropSession(int $fileId, string $key): void
    {
        if (!EditorSessionKey::forget($this->readKey($fileId), $key, time())) {
            return;
        }
        $this->cache->deleteItem($this->keyCacheId($fileId));
    }

    public function dropUser(int $fileId, int $userId): void
    {
        $stored = $this->readKey($fileId);
        if ($stored === null) {
            return;
        }
        $this->command($stored['key'], 'drop', '', [(string) $userId]);
    }

    /** @return array<string, mixed> */
    public function forcesave(string $key, string $userdata): array
    {
        return $this->command($key, 'forcesave', $userdata);
    }

    public function sign(int $groupId, int $fileId, int $userId, string $key, string $mode): string
    {
        return hash_hmac('sha256', $groupId . "\n" . $fileId . "\n" . $userId . "\n" . $key . "\n" . $mode, $this->appSecret);
    }

    public function signFile(string $purpose, int $groupId, int $fileId, string $key): string
    {
        return hash_hmac('sha256', $purpose . "\n" . $groupId . "\n" . $fileId . "\n" . $key, $this->appSecret);
    }

    public function markAck(int $fileId, string $saveId): void
    {
        $item = $this->cache->getItem($this->ackKey($fileId, $saveId));
        $item->set(true);
        $item->expiresAfter(120);
        $this->cache->save($item);
    }

    public function isAcked(int $fileId, string $saveId): bool
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $saveId)) {
            return false;
        }
        $item = $this->cache->getItem($this->ackKey($fileId, $saveId));

        return $item->isHit() && $item->get() === true;
    }

    private function newKey(int $fileId): string
    {
        $base = sprintf('fg%d-%s', $fileId, bin2hex(random_bytes(8)));

        return $base . '-' . substr(hash_hmac('sha256', 'file-group-key|' . $base, $this->appSecret), 0, 16);
    }

    private function rememberKey(int $fileId, string $key, bool $saved = false, ?int $at = null): void
    {
        // ponytail: cache.app, 24 ч. Сброс кэша рвёт «Сохранить» в открытой вкладке, пока файл не откроют снова.
        $item = $this->cache->getItem($this->keyCacheId($fileId));
        $item->set(['key' => $key, 'at' => $at ?? time(), 'saved' => $saved]);
        $item->expiresAfter(86400);
        $this->cache->save($item);
    }

    /**
     * @return array{key: string, at: int, saved: bool}|null
     */
    private function readKey(int $fileId): ?array
    {
        $item = $this->cache->getItem($this->keyCacheId($fileId));
        if (!$item->isHit()) {
            return null;
        }
        $stored = $item->get();
        if (!is_array($stored) || !is_string($stored['key'] ?? null) || $stored['key'] === '') {
            return null;
        }

        return [
            'key' => $stored['key'],
            'at' => (int) ($stored['at'] ?? 0),
            'saved' => ($stored['saved'] ?? false) === true,
        ];
    }

    private function keyCacheId(int $fileId): string
    {
        return 'file_group_editor_key_' . $fileId;
    }

    private function ackKey(int $fileId, string $saveId): string
    {
        return 'file_group_editor_' . $fileId . '_' . $saveId;
    }

    /**
     * @param list<string> $users
     *
     * @return array<string, mixed>
     */
    private function command(string $key, string $command, string $userdata, array $users = []): array
    {
        $commandUrl = 'http://onlyoffice/command?shardkey=' . rawurlencode($key);
        $body = ['c' => $command, 'key' => $key, 'userdata' => $userdata];
        if ($users !== []) {
            $body['users'] = array_values($users);
        }
        if ($this->jwt->isEnabled()) {
            $body['token'] = $this->jwt->encode($body);
        }
        $payload = json_encode($body);
        if ($payload === false) {
            return ['error' => 1, 'unreachable' => true];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($payload),
                'content' => $payload,
                'timeout' => 5,
            ],
        ]);
        $response = @file_get_contents($commandUrl, false, $context);
        if ($response === false) {
            return ['error' => 1, 'unreachable' => true];
        }
        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : ['error' => 1, 'unreachable' => true];
    }
}
