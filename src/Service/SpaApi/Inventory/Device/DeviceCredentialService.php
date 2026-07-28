<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Inventory\Device;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\CredentialViewLog;
use App\Entity\Inventory\Device;
use App\Entity\Inventory\DeviceCredential;
use App\Entity\User\User;
use App\Enum\Inventory\CredentialKind;
use App\Service\SpaApi\Inventory\CredentialCipher;
use App\Service\SpaApi\Inventory\InventoryHistoryService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Доступы устройств: создание, правка, удаление и раскрытие секрета.
 *
 * Инварианты безопасности (нарушение = компрометация инфраструктуры предприятия):
 *  - логин и пароль хранятся ТОЛЬКО в `secret_cipher` (sodium secretbox, CredentialCipher);
 *  - в обычных ответах API, в `inventory_history.payload` и в логах секрета нет НИКОГДА;
 *  - раскрытие — отдельная операция, только `ROLE_INVENTORY_ADMIN`, с обязательной записью
 *    в `inventory_credential_view_log`; журнал пишется ДО отдачи секрета, а не после;
 *  - удаление доступа с непустым журналом просмотров затирает секрет, но оставляет строку:
 *    в БД на журнал стоит RESTRICT, и потерять след того, что кто-то смотрел пароль,
 *    нельзя даже ценой «чистого» справочника.
 */
final class DeviceCredentialService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CredentialCipher $cipher,
        private readonly InventoryHistoryService $history,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(Device $device, array $payload, User $actor): DeviceCredential
    {
        $this->assertCipherConfigured();

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_NAME_REQUIRED);
        }

        $credential = new DeviceCredential();
        $credential->setDevice($device);
        $credential->setTitle($title);
        $credential->setKind($this->kind($payload['kind'] ?? null));
        $credential->setUrl($this->nullableString($payload['url'] ?? null));
        $credential->setNote($this->nullableString($payload['note'] ?? null));
        $this->sealSecret($credential, $payload);

        // Сначала flush — id нужен журналу; логировать под нулевым id значит потерять привязку.
        $this->em->persist($credential);
        $this->em->flush();

        // Только факт и метаданные: login/password в payload не попадают ни при каких условиях.
        $this->history->log('device_credential', (int) $credential->getId(), 'create', $actor, [
            'device_id' => $device->getId(),
            'title' => $title,
            'kind' => $credential->getKind()->value,
        ]);
        $this->em->flush();

        return $credential;
    }

    /**
     * Правка. Секрет перезаписывается ТОЛЬКО если в теле пришёл `login` или `password`:
     * иначе правка заголовка молча стирала бы пароль пустой строкой.
     *
     * @param array<string, mixed> $payload
     */
    public function update(DeviceCredential $credential, array $payload, User $actor): DeviceCredential
    {
        // Затёртая запись — надгробие, а не заготовка под новый доступ. Разрешив её оживить,
        // мы приписали бы новому секрету чужой журнал просмотров: в аудите осталось бы
        // «Иванов смотрел этот пароль», хотя пароль там уже другой (находка ревью Codex).
        if ($this->isWiped($credential)) {
            throw new ConflictHttpException(SpaApiError::INVENTORY_DOCUMENT_FROZEN);
        }

        if (\array_key_exists('title', $payload)) {
            $title = trim((string) $payload['title']);
            if ($title === '') {
                throw new BadRequestHttpException(SpaApiError::INVENTORY_NAME_REQUIRED);
            }
            $credential->setTitle($title);
        }
        if (\array_key_exists('kind', $payload)) {
            $credential->setKind($this->kind($payload['kind']));
        }
        if (\array_key_exists('url', $payload)) {
            $credential->setUrl($this->nullableString($payload['url']));
        }
        if (\array_key_exists('note', $payload)) {
            $credential->setNote($this->nullableString($payload['note']));
        }

        $secretTouched = \array_key_exists('login', $payload) || \array_key_exists('password', $payload);
        if ($secretTouched) {
            $this->assertCipherConfigured();
            $this->sealSecret($credential, $this->mergeWithStoredSecret($credential, $payload));
        }

        // Ключ намеренно назван без слов login/pass/secret: редактор истории вырезает
        // значения по подстроке в имени ключа, и «secret_changed» превратился бы в [REDACTED].
        $this->history->log('device_credential', (int) $credential->getId(), 'update', $actor, [
            'device_id' => $credential->getDevice()?->getId(),
            'rotated' => $secretTouched,
        ]);

        $this->em->flush();

        return $credential;
    }

    /**
     * Удаление. Если доступ хоть раз раскрывали — строку удалить нельзя (журнал ссылается
     * на неё с RESTRICT), поэтому затираем секрет и оставляем запись помеченной.
     *
     * @return bool true — секрет затёрт, строка осталась; false — строка удалена целиком
     */
    public function delete(DeviceCredential $credential, User $actor): bool
    {
        $credentialId = (int) $credential->getId();

        // Между «журнал пуст» и удалением строки помещается конкурентный reveal: он вставит
        // ссылку, и DELETE упадёт на RESTRICT пятисоткой (находка ревью Codex).
        // Блокируем доступ на запись и перечитываем журнал уже под блокировкой — тот же приём,
        // которым закрыт TOCTOU двойного проведения в StockPostingService.
        return (bool) $this->em->wrapInTransaction(function () use ($credential, $credentialId, $actor): bool {
            $this->em->lock($credential, LockMode::PESSIMISTIC_WRITE);

            $viewed = (int) $this->em->createQuery(
                'SELECT COUNT(l.id) FROM ' . CredentialViewLog::class . ' l WHERE l.credential = :credential',
            )->setParameter('credential', $credential)->getSingleScalarResult();

            if ($viewed > 0) {
                // NOT NULL в схеме, поэтому «нет секрета» = пустая строка, а не NULL.
                $credential->setSecretCipher('');
                $this->history->log('device_credential', $credentialId, 'wipe', $actor, [
                    'device_id' => $credential->getDevice()?->getId(),
                    'views' => $viewed,
                ]);
                $this->em->flush();

                return true;
            }

            $this->history->log('device_credential', $credentialId, 'delete', $actor, [
                'device_id' => $credential->getDevice()?->getId(),
            ]);
            $this->em->remove($credential);
            $this->em->flush();

            return false;
        });
    }

    /**
     * Раскрытие секрета. Вызывать ТОЛЬКО после проверки `ROLE_INVENTORY_ADMIN` в контроллере.
     *
     * Порядок операций важен и выбран так:
     *  1. расшифровать — операция чисто в памяти, побочных эффектов нет;
     *  2. записать просмотр и закоммитить;
     *  3. только потом вернуть значение вызывающему.
     *
     * Сначала расшифровка, потому что запись журнала до неё создавала бы ЛОЖНЫЙ след:
     * при повреждённом envelope или сменившемся ключе клиент получал 409, а в аудите
     * оставался просмотр, которого не было (находка ревью Codex). Обратный риск —
     * «секрет отдан, но не залогирован» — закрыт тем, что flush идёт ДО возврата:
     * упавший flush бросит исключение, и значение не покинет метод.
     *
     * @return array{login: ?string, password: ?string}
     */
    public function reveal(DeviceCredential $credential, User $actor): array
    {
        $this->assertCipherConfigured();

        $envelope = (string) $credential->getSecretCipher();
        if (trim($envelope) === '') {
            // Секрет затёрт при удалении — раскрывать нечего.
            throw new ConflictHttpException(SpaApiError::INVENTORY_NOT_FOUND);
        }

        try {
            $secret = $this->cipher->decrypt($envelope);
        } catch (\RuntimeException) {
            // Не пробрасываем текст исключения наружу: он описывает состояние ключа.
            throw new ConflictHttpException(SpaApiError::INVENTORY_CREDENTIALS_KEY_MISSING);
        }

        $log = new CredentialViewLog();
        $log->setCredential($credential);
        $log->setCredentialTitle((string) $credential->getTitle());
        $log->setDeviceName((string) $credential->getDevice()?->getName());
        $log->setUser($actor);
        $this->em->persist($log);

        $this->history->log('device_credential', (int) $credential->getId(), 'reveal', $actor, [
            'device_id' => $credential->getDevice()?->getId(),
        ]);
        $this->em->flush();

        return $secret;
    }

    /**
     * Дополняет частичную правку секрета тем, что уже лежит в хранилище.
     *
     * Логин и пароль лежат ОДНИМ envelope, поэтому перешифровать «только пароль» нельзя —
     * без этого шага `PATCH {"password": "..."}` затирал бы логин в null и наоборот
     * (находка ревью Codex). Расшифровка здесь не является раскрытием: значение не покидает
     * процесс и в журнал просмотров не пишется — там фиксируются показы человеку.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function mergeWithStoredSecret(DeviceCredential $credential, array $payload): array
    {
        $hasLogin = \array_key_exists('login', $payload);
        $hasPassword = \array_key_exists('password', $payload);
        if ($hasLogin && $hasPassword) {
            return $payload;
        }

        $envelope = (string) $credential->getSecretCipher();
        if (trim($envelope) === '') {
            return $payload;
        }

        try {
            $stored = $this->cipher->decrypt($envelope);
        } catch (\RuntimeException) {
            // Ключ сменился или запись повреждена: молча подставить «прежнее» нечего.
            // Требуем передать обе половины явно, а не гадаем.
            throw new BadRequestHttpException(SpaApiError::INVENTORY_INVALID_PAYLOAD);
        }

        if (!$hasLogin) {
            $payload['login'] = $stored['login'];
        }
        if (!$hasPassword) {
            $payload['password'] = $stored['password'];
        }

        return $payload;
    }

    private function isWiped(DeviceCredential $credential): bool
    {
        return trim((string) $credential->getSecretCipher()) === '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sealSecret(DeviceCredential $credential, array $payload): void
    {
        $login = $this->nullableString($payload['login'] ?? null);
        $password = $this->nullableString($payload['password'] ?? null);

        if ($login === null && $password === null) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_INVALID_PAYLOAD);
        }

        $sealed = $this->cipher->encrypt($login, $password);
        $credential->setSecretCipher($sealed['cipher']);
        $credential->setKeyVersion($sealed['keyVersion']);
    }

    private function assertCipherConfigured(): void
    {
        if (!$this->cipher->isConfigured()) {
            throw new ConflictHttpException(SpaApiError::INVENTORY_CREDENTIALS_KEY_MISSING);
        }
    }

    private function kind(mixed $value): CredentialKind
    {
        return CredentialKind::tryFrom((string) ($value ?? '')) ?? CredentialKind::ADMIN;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
