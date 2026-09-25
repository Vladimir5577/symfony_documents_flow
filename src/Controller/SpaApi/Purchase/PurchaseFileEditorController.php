<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\User\User;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Service\OnlyOffice\OnlyOfficeJwt;
use App\Service\Purchase\PurchaseAccess;
use App\Service\Purchase\PurchaseFileCoEditing;
use App\Service\Purchase\PurchaseFileStorageService;
use Aws\S3\Exception\S3Exception;
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
 * В хранилище итог попадает двумя путями: кнопкой «Сохранить» (forcesave,
 * status 6 — сессия продолжается) и когда вышел последний (status 2 — после
 * него файл открывается под новым ключом).
 *
 * Браузер ходит в /spa/api с JWT. Document Server скачивает файл и шлёт
 * callback без JWT пользователя, поэтому их адреса подписаны HMAC по ключу
 * документа. Подпись Document Server (ONLYOFFICE_JWT_SECRET) проверяется, если
 * задана.
 */
final class PurchaseFileEditorController extends AbstractController
{
    /**
     * Имя сервиса в compose — nginx, но на project-net так же резолвится фронтовый
     * nginx, и Document Server уходит туда в половине случаев. nginx_web — контейнер
     * Symfony, он один.
     */
    private const INTERNAL_APP = 'http://nginx_web';

    public function __construct(
        private readonly PurchaseRequestRepository $purchases,
        private readonly PurchaseAccess $access,
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

        $task = $this->access->findMyActiveTask($purchase, $user);
        $mode = $task !== null ? 'edit' : 'view';
        $key = $this->coEditing->documentKey($file);
        $base = sprintf('%s/purchase_file_editor/%d/%d', self::INTERNAL_APP, $purchase->getId(), $file->getId());
        $signed = fn (string $purpose): string => sprintf('%s/%s?%s', $base, $purpose, http_build_query([
            'key' => $key,
            'token' => $this->signFile($purpose, (int) $purchase->getId(), (int) $file->getId(), $key),
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
        if ($this->jwt->isEnabled()) {
            $config['token'] = $this->jwt->encode($config);
        }

        return $this->json([
            'mode' => $mode,
            'token' => $this->sign((int) $purchase->getId(), (int) $file->getId(), (int) $user->getId(), $key, $mode),
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

        $saveId = bin2hex(random_bytes(8));
        // Кто нажал «Сохранить» — в userdata: callback придёт на адрес того
        // участника, которого выберет Document Server, а не обязательно этого.
        $result = $this->command($session['key'], 'forcesave', sprintf('commit.%s.%d', $saveId, $user->getId()));
        $error = (int) ($result['error'] ?? 1);
        // 4 — документ не менялся с прошлого сохранения, callback не придёт.
        if ($error === 4) {
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
        $key = $this->keyFromQuery('callback', $id, $fileId, $request);
        $data = $key !== null ? $this->callbackData($request) : null;
        // Тело — про ту же сессию, что и подписанный адрес.
        if ($data === null || ($data['key'] ?? null) !== $key) {
            return $this->json(['error' => 0]);
        }

        $status = (int) ($data['status'] ?? 0);
        // 2 — все вышли, итог собран; 6 — «Сохранить» посреди сессии.
        if ($status !== 2 && $status !== 6) {
            return $this->json(['error' => 0]);
        }
        [$saveId, $committerId] = self::parseUserdata((string) ($data['userdata'] ?? ''));
        if ($saveId !== '' && $this->isAcked($fileId, $saveId)) {
            return $this->json(['error' => 0]);
        }

        $purchase = $this->purchases->find($id);
        $file = $this->findFile($purchase, $fileId);
        // Ключ уже сменился — это отставший callback прошлой сессии, её итог записан.
        if ($file === null || !$purchase instanceof PurchaseRequest || $this->coEditing->documentKey($file) !== $key) {
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

        $this->coEditing->commit($purchase, $file, $downloaded, $status === 2, $this->editorsOf($data, $committerId));
        if ($saveId !== '') {
            $this->markAck($fileId, $saveId);
        }

        return $this->json(['error' => 0]);
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
            return $data;
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

    /** @return array{0: string, 1: int} saveId и id нажавшего «Сохранить» */
    private static function parseUserdata(string $userdata): array
    {
        if (preg_match('/^commit\.([a-f0-9]{16})\.(\d+)$/', $userdata, $m) !== 1) {
            return ['', 0];
        }

        return [$m[1], (int) $m[2]];
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

    /** @return array{key: string, mode: string}|null */
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
        if (!hash_equals($this->sign($purchaseId, $fileId, (int) $user->getId(), $key, $mode), $token)) {
            return null;
        }

        return ['key' => $key, 'mode' => $mode];
    }

    /** Ключ документа из подписанного адреса файла или callback; null — подпись не сошлась. */
    private function keyFromQuery(string $purpose, int $purchaseId, int $fileId, Request $request): ?string
    {
        $key = (string) $request->query->get('key', '');
        $token = (string) $request->query->get('token', '');
        if ($key === '' || $token === '' || !hash_equals($this->signFile($purpose, $purchaseId, $fileId, $key), $token)) {
            return null;
        }

        return $key;
    }

    /** Подпись сессии участника: ею браузер подтверждает «Сохранить». */
    private function sign(int $purchaseId, int $fileId, int $userId, string $key, string $mode): string
    {
        return hash_hmac('sha256', $purchaseId . "\n" . $fileId . "\n" . $userId . "\n" . $key . "\n" . $mode, $this->appSecret);
    }

    /**
     * Подпись адресов файла (content) и callback: они общие на сессию, пользователя
     * в них нет. Назначение входит в подпись — по адресу чтения запись не собрать.
     */
    private function signFile(string $purpose, int $purchaseId, int $fileId, string $key): string
    {
        return hash_hmac('sha256', $purpose . "\n" . $purchaseId . "\n" . $fileId . "\n" . $key, $this->appSecret);
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
        if ($this->jwt->isEnabled()) {
            $body['token'] = $this->jwt->encode($body);
        }
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
