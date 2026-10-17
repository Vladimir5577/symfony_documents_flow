<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseContractReview;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseHistoryAction;
use App\Repository\Purchase\PurchaseRequestFileRepository;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Service\OnlyOffice\OnlyOfficeJwt;
use App\Service\Purchase\PurchaseAccess;
use App\Service\Purchase\PurchaseFileCoEditing;
use App\Service\Purchase\PurchaseFileStorageService;
use Aws\S3\Exception\S3Exception;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Редактор docx заявки через OnlyOffice — один документ на всех.
 *
 * Все, кто открыл файл, попадают в одну сессию по общему ключу
 * (PurchaseFileCoEditing::documentKey) и видят правки друг друга сразу. Правит
 * тот, у кого задача на текущем этапе (лично, по роли или админ), остальные
 * смотрят. Правка идёт рецензированием с цветом отдела — см. PurchaseFileCoEditing.
 *
 * В хранилище файл попадает только по «Сохранить», «Рецензия пройдена» и
 * «Утвердить рецензии»: команда forcesave, status 6 с userdata commit.*.
 * Утверждение дописывает .accept: перед записью все правки принимаются,
 * заливка отделов снимается, ключ сессии сменяется.
 * Когда вышел последний (status 2), сессия закрывается без записи.
 *
 * Браузер ходит в /spa/api с JWT. Document Server скачивает файл и шлёт
 * callback без JWT пользователя, поэтому их адреса подписаны HMAC по ключу
 * документа и серверной сессии. Подпись Document Server (ONLYOFFICE_JWT_SECRET)
 * обязательна.
 */
final class PurchaseFileEditorController extends AbstractController
{
    /**
     * Имя сервиса в compose — nginx, но на project-net так же резолвится фронтовый
     * nginx, и Document Server уходит туда в половине случаев. nginx_web — контейнер
     * Symfony, он один.
     */
    private const INTERNAL_APP = 'http://nginx_web';
    private const SESSION_TTL = 7200;

    public function __construct(
        private readonly PurchaseRequestRepository $purchases,
        private readonly PurchaseAccess $access,
        private readonly PurchaseRequestFileRepository $files,
        private readonly PurchaseFileStorageService $storage,
        private readonly PurchaseFileCoEditing $coEditing,
        private readonly OnlyOfficeJwt $jwt,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
        #[Autowire('%onlyoffice_document_server_url%')]
        private readonly string $onlyofficeDocumentServerUrl,
    ) {
    }

    #[Route('/spa/api/purchases/{id}/files/{fileId}/editor', name: 'spa_api_purchases_file_editor_open', requirements: ['id' => '\d+', 'fileId' => '\d+'], methods: ['POST'])]
    public function open(int $id, int $fileId, #[CurrentUser] ?User $user): JsonResponse
    {
        $found = $this->findEditable($id, $fileId, $user);
        if ($found instanceof JsonResponse) {
            return $found;
        }
        [$purchase, $file] = $found;
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $this->jwt->requireEnabled();
        $task = $this->access->findMyActiveTask($purchase, $user);
        // Утверждённый договор и его копия только смотрятся, даже на своём шаге.
        if ($file->getSourceFile() !== null || $file->belongsToConfirmedContract() || $this->files->isConfirmed((int) $file->getId())) {
            $task = null;
        }
        $mode = $task !== null ? 'edit' : 'view';
        $key = $this->documentKey($purchase, $file);
        $sessionToken = bin2hex(random_bytes(32));
        $item = $this->cache->getItem('purchase_editor_session_' . $sessionToken);
        $item->set([
            'purchaseId' => $id, 'fileId' => $fileId, 'userId' => $user->getId(),
            'key' => $key, 'mode' => $mode, 'taskId' => $task?->getId(),
            'stageId' => $purchase->getCurrentStage()?->getId(),
            'status' => $purchase->getStatus()->value, 'routeRevision' => $this->routeRevision($purchase),
            'expiresAt' => time() + self::SESSION_TTL,
        ]);
        $item->expiresAfter(self::SESSION_TTL);
        $this->cache->save($item);
        $base = sprintf('%s/purchase_file_editor/%d/%d', self::INTERNAL_APP, $purchase->getId(), $file->getId());
        $signed = fn (string $purpose): string => sprintf('%s/%s?%s', $base, $purpose, http_build_query([
            'key' => $key,
            'session' => $sessionToken,
            'token' => $this->signFile($purpose, $id, $fileId, $key, $sessionToken),
        ]));

        // Адрес callback — только правщику: с ним можно записать файл, а зрителю
        // (и всем, кто видит заявку) писать нечего.
        $config = $this->coEditing->editorConfig(
            $file,
            $user,
            $task,
            $signed('content'),
            $task !== null ? $signed('callback') : null,
        );
        $config['document']['key'] = $key;
        $config['token'] = $this->jwt->encode($config + ['exp' => time() + self::SESSION_TTL]);

        return $this->json([
            'mode' => $mode,
            'token' => $sessionToken,
            'taskId' => $task?->getId(),
            'contractReview' => $file->getType() === PurchaseFileType::CONTRACT
                ? $task?->getContractReview()?->value
                : null,
            'documentServerUrl' => rtrim($this->onlyofficeDocumentServerUrl, '/'),
            'config' => $config,
        ]);
    }

    #[Route('/spa/api/purchases/{id}/files/{fileId}/editor', name: 'spa_api_purchases_file_editor_status', requirements: ['id' => '\d+', 'fileId' => '\d+'], methods: ['GET'])]
    public function status(int $id, int $fileId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $found = $this->findVisible($id, $fileId, $user);
        if ($found instanceof JsonResponse) {
            return $found;
        }

        return $this->json([
            'saveAck' => $this->isAcked($fileId, (string) $request->query->get('saveId', '')),
        ]);
    }

    #[Route('/spa/api/purchases/{id}/files/{fileId}/editor/heartbeat', name: 'spa_api_purchases_file_editor_heartbeat', requirements: ['id' => '\d+', 'fileId' => '\d+'], methods: ['POST'])]
    public function heartbeat(int $id, int $fileId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $session = $this->sessionFromBody($id, $fileId, $user, $request);
        if ($session === null) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $token = $session['token'];
        unset($session['token']);
        $session['expiresAt'] = time() + self::SESSION_TTL;
        $item = $this->cache->getItem('purchase_editor_session_' . $token);
        $item->set($session);
        $item->expiresAfter(self::SESSION_TTL);
        if (!$this->cache->save($item)) {
            return $this->json(['error' => SpaApiError::PURCHASE_FILE_EDITOR_FAILED], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json(['expiresAt' => $session['expiresAt']]);
    }

    #[Route('/spa/api/purchases/{id}/files/{fileId}/editor/save', name: 'spa_api_purchases_file_editor_save', requirements: ['id' => '\d+', 'fileId' => '\d+'], methods: ['POST'])]
    public function save(int $id, int $fileId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $found = $this->findEditable($id, $fileId, $user);
        if ($found instanceof JsonResponse) {
            return $found;
        }
        $session = $this->sessionFromBody($id, $fileId, $user, $request);
        if ($session === null || $session['mode'] !== 'edit' || !$user instanceof User) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }
        [$purchase, $file] = $found;
        // Флаг с клиента сам по себе не принимает правки: только задача «утвердить».
        $accept = $this->acceptRequested($request)
            && $this->access->findMyActiveTask($purchase, $user)?->getContractReview() === PurchaseContractReview::ACCEPT;

        $saveId = bin2hex(random_bytes(8));
        // Callback может прийти на URL другого соавтора: разрешение относится к нажавшему «Сохранить».
        $pending = $this->cache->getItem('purchase_editor_save_' . $fileId . '_' . $saveId);
        $pending->set(['session' => $session['token'], 'accept' => $accept]);
        $pending->expiresAfter(120);
        $this->cache->save($pending);
        // Кто нажал «Сохранить» — в userdata: callback придёт на адрес того
        // участника, которого выберет Document Server, а не обязательно этого.
        $result = $this->command(
            $session['key'],
            'forcesave',
            sprintf('commit.%s.%d%s', $saveId, $user->getId(), $accept ? '.accept' : ''),
        );
        $error = (int) ($result['error'] ?? 1);
        // 4 — документ не менялся с прошлого сохранения, callback не придёт.
        if ($error === 4) {
            if ($accept) {
                $accepted = $this->acceptStoredForSession($purchase, $file, $user, $session['token']);
                if ($accepted === null) {
                    return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
                }
                if (!$accepted) {
                    return $this->json(['error' => SpaApiError::PURCHASE_FILE_EDITOR_FAILED], Response::HTTP_BAD_GATEWAY);
                }
            }
            $this->markAck($fileId, $saveId);

            return $this->json(['saveId' => $saveId, 'saveAck' => true]);
        }
        if ($error !== 0) {
            return $this->json(['error' => SpaApiError::PURCHASE_FILE_EDITOR_FAILED], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json(['saveId' => $saveId, 'saveAck' => false]);
    }

    #[Route('/purchase_file_editor/{id}/{fileId}/content', name: 'purchase_file_editor_content', requirements: ['id' => '\d+', 'fileId' => '\d+'], methods: ['GET'])]
    public function content(int $id, int $fileId, Request $request): Response
    {
        if ($this->keyFromQuery('content', $id, $fileId, $request) === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        $file = $this->findFile($this->purchases->find($id), $fileId);
        if ($file === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        try {
            $object = $this->storage->getObject($file->getStorageKey());
        } catch (S3Exception) {
            return $this->json(['error' => SpaApiError::PURCHASE_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $stream = $object['Body'];
        $response = new StreamedResponse(static function () use ($stream): void {
            while (!$stream->eof()) {
                echo $stream->read(8192);
            }
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        if ($object['ContentLength'] !== null) {
            $response->headers->set('Content-Length', (string) $object['ContentLength']);
        }
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/purchase_file_editor/{id}/{fileId}/callback', name: 'purchase_file_editor_callback', requirements: ['id' => '\d+', 'fileId' => '\d+'], methods: ['POST'])]
    public function callback(int $id, int $fileId, Request $request): JsonResponse
    {
        // URL выбирает Document Server: его владелец не обязательно инициатор сохранения.
        $key = $this->keyFromQuery('callback', $id, $fileId, $request);
        $data = $key !== null ? $this->callbackData($request) : null;
        // Тело — про ту же сессию, что и подписанный адрес.
        if ($data === null || ($data['key'] ?? null) !== $key) {
            return $this->json(['error' => 0]);
        }

        $status = (int) ($data['status'] ?? 0);
        // 2 — все вышли. Автосохранение и закрытие файл не пишут.
        if ($status === 2) {
            if ($this->keyFromQuery('callback', $id, $fileId, $request) !== null) {
                $this->dropUnsavedSession($id, $fileId, $key);
            }

            return $this->json(['error' => 0]);
        }
        // 6 — только наша команда: userdata commit.{saveId}.{userId}.
        // Таймер и кнопка самого OnlyOffice приходят без неё.
        if ($status !== 6) {
            return $this->json(['error' => 0]);
        }
        [$saveId, $committerId, $accept] = self::parseUserdata((string) ($data['userdata'] ?? ''));
        if ($saveId === '' || $this->isAcked($fileId, $saveId)) {
            return $this->json(['error' => 0]);
        }

        $purchase = $this->purchases->find($id);
        $file = $this->findFile($purchase, $fileId);
        // Ключ уже сменился — это отставший callback прошлой сессии, её итог записан.
        if ($file === null || !$purchase instanceof PurchaseRequest || $this->documentKey($purchase, $file) !== $key) {
            return $this->json(['error' => 0]);
        }
        $pending = $this->cache->getItem('purchase_editor_save_' . $fileId . '_' . $saveId);
        $permission = $pending->isHit() ? $pending->get() : null;
        $committer = is_array($permission)
            ? $this->cachedSession((string) ($permission['session'] ?? ''), $id, $fileId)
            : null;
        if ($committer === null || $committer['mode'] !== 'edit' || $committer['key'] !== $key
            || $committer['userId'] !== $committerId || ($permission['accept'] ?? null) !== $accept
        ) {
            return $this->json(['error' => 0]);
        }
        $url = self::internalDownloadUrl((string) ($data['url'] ?? ''), $this->onlyofficeDocumentServerUrl);
        if ($url === null) {
            return $this->json(['error' => 1]);
        }
        $downloaded = @file_get_contents($url);
        if ($downloaded === false || $downloaded === '') {
            return $this->json(['error' => 1]);
        }
        $this->commitDownloaded($purchase, $file, $data, $downloaded, $request);

        return $this->json(['error' => 0]);
    }

    /** @param array<string, mixed> $data */
    private function commitDownloaded(PurchaseRequest $purchase, PurchaseRequestFile $file, array $data, string $downloaded, Request $request): void
    {
        // Задача могла завершиться, пока Document Server отдавал файл. Блокировка общая с approveTask.
        $committed = $this->em->wrapInTransaction(function () use ($purchase, $file, $data, $downloaded, $request): bool {
            $this->refreshEditorState($purchase, $file);
            $id = (int) $purchase->getId();
            $fileId = (int) $file->getId();
            [$saveId, $committerId, $accept] = self::parseUserdata((string) ($data['userdata'] ?? ''));
            $pending = $this->cache->getItem('purchase_editor_save_' . $fileId . '_' . $saveId);
            $permission = $pending->isHit() ? $pending->get() : null;
            $currentUser = $this->em->find(User::class, $committerId);
            if ($currentUser instanceof User) {
                $this->em->refresh($currentUser);
            }
            $committer = is_array($permission)
                ? $this->activeSession((string) ($permission['session'] ?? ''), $id, $fileId)
                : null;
            $user = $committer !== null ? $this->em->find(User::class, $committer['userId']) : null;
            if ($committer === null || $committer['mode'] !== 'edit' || $committer['key'] !== ($data['key'] ?? null)
                || $committer['userId'] !== $committerId || ($permission['accept'] ?? null) !== $accept
                || !$user instanceof User || $this->isAcked($fileId, $saveId)
                || $this->keyFromQuery('callback', $id, $fileId, $request) === null
                || ($accept && $this->access->findMyActiveTask($purchase, $user)?->getContractReview() !== PurchaseContractReview::ACCEPT)
            ) {
                return false;
            }
            if ($accept) {
                $downloaded = PurchaseFileCoEditing::acceptedCopy($downloaded);
            }
            $this->coEditing->commit($purchase, $file, $downloaded, $accept, $this->editorsOf($data, $committerId));

            return true;
        });
        if ($committed) {
            [$saveId] = self::parseUserdata((string) ($data['userdata'] ?? ''));
            $this->markAck((int) $file->getId(), $saveId);
        }
    }

    /** Вызывается внутри транзакции, общей с изменением файла. */
    private function refreshEditorState(PurchaseRequest $purchase, PurchaseRequestFile $file): void
    {
        $this->em->refresh($purchase, LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($file);
        foreach ($purchase->getStages() as $stage) {
            $this->em->refresh($stage);
            foreach ($stage->getTasks() as $task) {
                $this->em->refresh($task);
            }
        }
    }

    /** Все вышли без «Сохранить»: файл остаётся как был, ключ сессии сменяется. */
    private function dropUnsavedSession(int $id, int $fileId, string $key): void
    {
        $purchase = $this->purchases->find($id);
        $file = $this->findFile($purchase, $fileId);
        if ($file === null || !$purchase instanceof PurchaseRequest || $this->documentKey($purchase, $file) !== $key) {
            return;
        }
        $this->coEditing->dropSession($file);
    }

    /**
     * Адрес собранного файла из callback → внутренний адрес Document Server;
     * null — адрес не его.
     *
     * Без проверки file_get_contents прочитал бы что угодно из тела запроса:
     * file://, php://filter или внутренний сервис. Файл отдаёт только кэш
     * Document Server — по публичному адресу (как его видит браузер) или
     * внутреннему http://onlyoffice.
     */
    public static function internalDownloadUrl(string $url, string $documentServerUrl): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true) || !isset($parts['host'])) {
            return null;
        }
        $path = $parts['path'] ?? '';

        $prefix = null;
        if ($parts['host'] === 'onlyoffice') {
            $prefix = '';
        } else {
            $public = parse_url($documentServerUrl);
            if (is_array($public)
                && ($public['scheme'] ?? null) === $parts['scheme']
                && strcasecmp($public['host'] ?? '', $parts['host']) === 0
                && ($public['port'] ?? null) === ($parts['port'] ?? null)
            ) {
                $prefix = rtrim($public['path'] ?? '', '/');
            }
        }
        if ($prefix === null || !str_starts_with($path, $prefix . '/cache/files/')) {
            return null;
        }

        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return 'http://onlyoffice:80' . substr($path, strlen($prefix)) . $query;
    }

    /**
     * Тело callback; с подписью Document Server — только если токен сошёлся.
     *
     * @return array<string, mixed>|null
     */
    private function callbackData(Request $request): ?array
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return null;
        }
        if (!$this->jwt->isEnabled()) {
            return null;
        }

        $token = is_string($data['token'] ?? null) ? $data['token'] : null;
        $header = (string) $request->headers->get('Authorization', '');
        if ($token === null && str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);
        }
        $payload = $token !== null ? $this->jwt->decode($token) : null;
        if ($payload === null) {
            return null;
        }

        // В заголовке Document Server кладёт тело в поле payload, в теле — как есть.
        return is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;
    }

    /**
     * Кто правил: нажавший «Сохранить» первым, затем те, кого назвал Document Server.
     *
     * @param array<string, mixed> $data
     * @return list<User>
     */
    private function editorsOf(array $data, int $committerId): array
    {
        $ids = $committerId > 0 ? [$committerId] : [];
        foreach ((array) ($data['users'] ?? []) as $raw) {
            // Рецензент в сессии — код роли (LEGAL), не человек. Числовой id — только
            // из userdata «Сохранить»; у роли ведущих цифр нет, в актёры она не попадёт.
            // Document Server может дописать к id номер подключения — берём ведущие цифры.
            if (preg_match('/^\d+/', (string) $raw, $m) === 1) {
                $ids[] = (int) $m[0];
            }
        }

        $editors = [];
        foreach (array_unique($ids) as $userId) {
            $user = $this->em->find(User::class, $userId);
            if ($user instanceof User) {
                $editors[] = $user;
            }
        }

        return $editors;
    }

    /** @return array{0: string, 1: int, 2: bool} saveId, id нажавшего и «принять все правки» */
    private static function parseUserdata(string $userdata): array
    {
        if (preg_match('/^commit\.([a-f0-9]{16})\.(\d+)(\.accept)?$/', $userdata, $m) !== 1) {
            return ['', 0, false];
        }

        return [$m[1], (int) $m[2], ($m[3] ?? '') === '.accept'];
    }

    private function acceptRequested(Request $request): bool
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) && ($data['acceptRevisions'] ?? false) === true;
    }

    /**
     * Сессия не прислала новый файл. Принять правки в том, что уже лежит в хранилище.
     * false — файл не прочитался, кнопку утверждения повторяют.
     * null — право утверждения закончилось, пока выполнялась команда Document Server.
     */
    private function acceptStoredForSession(PurchaseRequest $purchase, PurchaseRequestFile $file, User $user, string $token): ?bool
    {
        return $this->em->wrapInTransaction(function () use ($purchase, $file, $user, $token): ?bool {
            $this->refreshEditorState($purchase, $file);
            $this->em->refresh($user);
            $session = $this->activeSession($token, (int) $purchase->getId(), (int) $file->getId());
            if ($session === null || $session['mode'] !== 'edit' || $session['userId'] !== $user->getId()
                || $this->access->findMyActiveTask($purchase, $user)?->getContractReview() !== PurchaseContractReview::ACCEPT
            ) {
                return null;
            }

            return $this->acceptStored($purchase, $file, $user);
        });
    }

    private function acceptStored(PurchaseRequest $purchase, PurchaseRequestFile $file, User $user): bool
    {
        try {
            $stored = (string) $this->storage->getObject($file->getStorageKey())['Body'];
        } catch (S3Exception) {
            return false;
        }
        $accepted = PurchaseFileCoEditing::acceptedCopy($stored);
        if ($accepted !== $stored) {
            $this->coEditing->commit($purchase, $file, $accepted, true, [$user]);
        }

        return true;
    }

    /**
     * @return array{0: PurchaseRequest, 1: PurchaseRequestFile}|JsonResponse
     */
    private function findEditable(int $id, int $fileId, ?User $user): array|JsonResponse
    {
        $found = $this->findVisible($id, $fileId, $user);
        if ($found instanceof JsonResponse) {
            return $found;
        }
        if (!$this->isDocx($found[1])) {
            return $this->json(['error' => SpaApiError::PURCHASE_INVALID_FILE_TYPE], Response::HTTP_BAD_REQUEST);
        }

        return $found;
    }

    /**
     * @return array{0: PurchaseRequest, 1: PurchaseRequestFile}|JsonResponse
     */
    private function findVisible(int $id, int $fileId, ?User $user): array|JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchases->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        if (!$this->access->canView($purchase, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $file = $this->findFile($purchase, $fileId);
        if ($file === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        return [$purchase, $file];
    }

    private function findFile(?PurchaseRequest $purchase, int $fileId): ?PurchaseRequestFile
    {
        if ($purchase === null) {
            return null;
        }
        foreach ($purchase->getFiles() as $file) {
            if ($file->getId() === $fileId) {
                return $file;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function sessionFromBody(int $purchaseId, int $fileId, ?User $user, Request $request): ?array
    {
        if (!$user instanceof User) {
            return null;
        }
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return null;
        }

        $key = (string) ($data['key'] ?? '');
        $mode = (string) ($data['mode'] ?? '');
        $token = (string) ($data['token'] ?? '');
        if ($key === '' || $token === '' || !in_array($mode, ['edit', 'view'], true)) {
            return null;
        }
        $session = $this->activeSession($token, $purchaseId, $fileId);
        if ($session === null || $session['userId'] !== $user->getId()
            || $session['key'] !== $key || $session['mode'] !== $mode
        ) {
            return null;
        }

        return $session + ['token' => $token];
    }

    /** Ключ документа из подписанного адреса файла или callback; null — подпись не сошлась. */
    private function keyFromQuery(string $purpose, int $purchaseId, int $fileId, Request $request): ?string
    {
        $key = (string) $request->query->get('key', '');
        $token = (string) $request->query->get('token', '');
        $sessionToken = (string) $request->query->get('session', '');
        if ($key === '' || $token === '' || !hash_equals($this->signFile($purpose, $purchaseId, $fileId, $key, $sessionToken), $token)) {
            return null;
        }
        if ($purpose === 'callback') {
            $purchase = $this->purchases->find($purchaseId);
            $file = $this->findFile($purchase, $fileId);

            return $purchase instanceof PurchaseRequest && $file !== null && $this->documentKey($purchase, $file) === $key
                ? $key : null;
        }
        $session = $this->activeSession($sessionToken, $purchaseId, $fileId);
        if ($session === null || $session['key'] !== $key) {
            return null;
        }

        return $key;
    }

    /** Серверное разрешение проверяется заново, включая отзыв роли и завершение задачи. */
    private function activeSession(string $token, int $purchaseId, int $fileId): ?array
    {
        $session = $this->cachedSession($token, $purchaseId, $fileId);
        if ($session === null) {
            return null;
        }
        $purchase = $this->purchases->find($purchaseId);
        $file = $this->findFile($purchase, $fileId);
        $user = $this->em->find(User::class, $session['userId']);
        if (!$purchase instanceof PurchaseRequest || $file === null
            || !$user instanceof User || $user->isDeleted() || !$this->access->canView($purchase, $user)
            || $this->documentKey($purchase, $file) !== $session['key']
            || $purchase->getCurrentStage()?->getId() !== $session['stageId']
            || $purchase->getStatus()->value !== $session['status']
            || $this->routeRevision($purchase) !== $session['routeRevision']
        ) {
            $this->cache->deleteItem('purchase_editor_session_' . $token);

            return null;
        }
        if ($session['mode'] === 'edit'
            && ($session['taskId'] === null || $this->access->findMyActiveTask($purchase, $user)?->getId() !== $session['taskId'])
        ) {
            return null;
        }

        return $session;
    }

    /** @return array<string, mixed>|null */
    private function cachedSession(string $token, int $purchaseId, int $fileId): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return null;
        }
        $cacheKey = 'purchase_editor_session_' . $token;
        $item = $this->cache->getItem($cacheKey);
        $session = $item->isHit() ? $item->get() : null;
        if (!is_array($session) || $session['purchaseId'] !== $purchaseId || $session['fileId'] !== $fileId) {
            return null;
        }
        if ($session['expiresAt'] <= time()) {
            $this->cache->deleteItem($cacheKey);

            return null;
        }

        return $session;
    }

    /** Новый этап не подключается к документу, который ещё открыт у прежних редакторов. */
    private function documentKey(PurchaseRequest $purchase, PurchaseRequestFile $file): string
    {
        $stage = $purchase->getCurrentStage()?->getId() . ':' . $purchase->getStatus()->value . ':' . $this->routeRevision($purchase);

        return $this->coEditing->documentKey($file) . '-' . substr(hash('sha256', $stage), 0, 16);
    }

    /** Возврат на тот же этап не должен оживлять его старые разрешения. */
    private function routeRevision(PurchaseRequest $purchase): int
    {
        $revision = 0;
        foreach ($purchase->getHistory() as $entry) {
            if (in_array($entry->getAction(), [
                PurchaseHistoryAction::SUBMITTED,
                PurchaseHistoryAction::TASK_REVOKED,
                PurchaseHistoryAction::RETURNED_TO_DEPARTMENT,
            ], true)) {
                $revision = max($revision, (int) $entry->getId());
            }
        }

        return $revision;
    }

    /**
     * Назначение входит в подпись — по адресу чтения запись не собрать.
     */
    private function signFile(string $purpose, int $purchaseId, int $fileId, string $key, string $session): string
    {
        return hash_hmac('sha256', $purpose . "\n" . $purchaseId . "\n" . $fileId . "\n" . $key . "\n" . $session, $this->appSecret);
    }

    private function isDocx(PurchaseRequestFile $file): bool
    {
        return strtolower(pathinfo($file->getStorageKey(), PATHINFO_EXTENSION)) === 'docx';
    }

    private function markAck(int $fileId, string $saveId): void
    {
        $item = $this->cache->getItem($this->ackKey($fileId, $saveId));
        $item->set(true);
        $item->expiresAfter(120);
        $this->cache->save($item);
    }

    private function isAcked(int $fileId, string $saveId): bool
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $saveId)) {
            return false;
        }
        $item = $this->cache->getItem($this->ackKey($fileId, $saveId));

        return $item->isHit() && $item->get() === true;
    }

    private function ackKey(int $fileId, string $saveId): string
    {
        return 'purchase_file_editor_' . $fileId . '_' . $saveId;
    }

    /** @return array<string, mixed> */
    private function command(string $key, string $command, ?string $userdata = null): array
    {
        $commandUrl = 'http://onlyoffice/command?shardkey=' . rawurlencode($key);
        $body = ['c' => $command, 'key' => $key];
        if ($userdata !== null) {
            $body['userdata'] = $userdata;
        }
        $body['token'] = $this->jwt->encode($body);
        $payload = json_encode($body);
        if ($payload === false) {
            return ['error' => 1];
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
            return ['error' => 1];
        }
        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : ['error' => 1];
    }
}
