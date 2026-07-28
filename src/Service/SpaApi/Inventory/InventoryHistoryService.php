<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Inventory;

use App\Entity\Inventory\History;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Аудит модуля инвентаризации. Payload НИКОГДА не содержит секретов:
 * значения login/password кредов сюда не передавать ни в каком виде.
 */
final class InventoryHistoryService
{
    /**
     * Подстроки в имени ключа, при которых значение вырезается.
     *
     * Именно подстроки, а не точные имена: точный denylist пропускал `old_password`,
     * `pass`, `credentials`, `auth_token` — то есть ровно те варианты, которые появляются,
     * когда кто-то добавляет новое событие и не думает про редактор (находка ревью Codex).
     * Здесь дешевле перестраховаться: лишний `[REDACTED]` в аудите безвреден,
     * утёкший пароль — нет.
     */
    private const REDACTED_SUBSTRINGS = ['login', 'pass', 'secret', 'cipher', 'token', 'credential', 'key'];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    public function log(string $entityType, int $entityId, string $action, ?User $actor, ?array $payload = null): void
    {
        $history = new History();
        $history->setEntityType($entityType);
        $history->setEntityId($entityId);
        $history->setAction($action);
        $history->setUser($actor);
        $history->setPayload($payload === null ? null : $this->redact($payload));

        $this->em->persist($history);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $payload[$key] = '[REDACTED]';
                continue;
            }
            if (\is_array($value)) {
                $payload[$key] = $this->redact($value);
            }
        }

        return $payload;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = mb_strtolower($key);
        foreach (self::REDACTED_SUBSTRINGS as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
