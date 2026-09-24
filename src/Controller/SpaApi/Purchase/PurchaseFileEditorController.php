<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseHistoryAction;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Service\Purchase\PurchaseAccess;
use App\Service\Purchase\PurchaseFileStorageService;
use App\Service\Purchase\PurchaseHistoryLogger;
use App\Service\Purchase\PurchaseRequestEditor;
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
 * Редактор docx заявки через OnlyOffice.
 *
 * Свой шаг (есть активная задача: лично, по роли или админ) — правка.
 * Остальные, кому видна заявка, — просмотр. Замка нет: каждая вкладка
 * со своим ключом, в MinIO остаётся последнее «Сохранить».
 *
 * Браузер ходит в /spa/api с JWT. Document Server скачивает файл и шлёт
 * callback без JWT, поэтому url подписан HMAC. JWT_ENABLED на Document Server
 * выключен — права edit/view задаёт конфиг, который собирает open().
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
        private readonly PurchaseRequestEditor $editor,
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

        $mode = $this->access->findMyActiveTask($purchase, $user) !== null ? 'edit' : 'view';
        $key = 'pf' . $file->getId() . bin2hex(random_bytes(8));
        $token = $this->sign((int) $purchase->getId(), (int) $file->getId(), (int) $user->getId(), $key, $mode);
        $query = http_build_query([
            'uid' => $user->getId(),
            'key' => $key,
            'mode' => $mode,
            'token' => $token,
        ]);
        $base = sprintf('%s/purchase_file_editor/%d/%d', self::INTERNAL_APP, $purchase->getId(), $file->getId());

        return $this->json([
            'mode' => $mode,
            'token' => $token,
            'documentServerUrl' => rtrim($this->onlyofficeDocumentServerUrl, '/'),
            'document' => [
                'fileType' => 'docx',
                'key' => $key,
                'title' => $file->getOriginalName() ?: 'document.docx',
                'url' => $base . '/content?' . $query,
                'permissions' => $mode === 'edit'
                    ? ['edit' => true, 'review' => true]
                    : ['edit' => false, 'review' => false, 'comment' => false],
            ],
            'callbackUrl' => $base . '/callback?' . $query,
            'user' => [
                'id' => $user->getId(),
                'name' => PurchaseHistoryLogger::nameOf($user),
            ],
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
        if ($session === null || $session['mode'] !== 'edit') {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $saveId = bin2hex(random_bytes(8));
        $result = $this->command($session['key'], 'forcesave', 'commit.' . $saveId);
        $error = (int) ($result['error'] ?? 1);
        // 4 — документ не менялся, callback не придёт.
        if ($error === 4) {
            $this->markAck($fileId, $saveId);

            return $this->json(['saveId' => $saveId, 'saveAck' => true]);
        }
        if ($error !== 0) {
            return $this->json(['error' => SpaApiError::PURCHASE_FILE_EDITOR_FAILED], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json(['saveId' => $saveId, 'saveAck' => false]);
    }

    #[Route('/spa/api/purchases/{id}/files/{fileId}/editor/discard', name: 'spa_api_purchases_file_editor_discard', requirements: ['id' => '\d+', 'fileId' => '\d+'], methods: ['POST'])]
    public function discard(int $id, int $fileId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $found = $this->findEditable($id, $fileId, $user);
        if ($found instanceof JsonResponse) {
            return $found;
        }
        $session = $this->sessionFromBody($id, $fileId, $user, $request);
        if ($session !== null) {
            $this->command($session['key'], 'drop');
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/purchase_file_editor/{id}/{fileId}/content', name: 'purchase_file_editor_content', requirements: ['id' => '\d+', 'fileId' => '\d+'], methods: ['GET'])]
    public function content(int $id, int $fileId, Request $request): Response
    {
        if ($this->sessionFromQuery($id, $fileId, $request) === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        $purchase = $this->purchases->find($id);
        $file = $this->findFile($purchase, $fileId);
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
        $session = $this->sessionFromQuery($id, $fileId, $request);
        if ($session === null) {
            return $this->json(['error' => 0]);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 0]);
        }

        $status = (int) ($data['status'] ?? 0);
        $userdata = (string) ($data['userdata'] ?? '');
        $saveId = str_starts_with($userdata, 'commit.') ? substr($userdata, 7) : '';
        // В MinIO только кнопка «Сохранить» (forcesave, status 6). Закрытие вкладки — status 2.
        if ($session['mode'] !== 'edit' || $status !== 6 || !preg_match('/^[a-f0-9]{16}$/', $saveId)) {
            return $this->json(['error' => 0]);
        }
        if ($this->isAcked($fileId, $saveId)) {
            return $this->json(['error' => 0]);
        }
        if (empty($data['url'])) {
            return $this->json(['error' => 1]);
        }

        $url = str_replace($this->onlyofficeDocumentServerUrl, 'http://onlyoffice:80', (string) $data['url']);
        $downloaded = @file_get_contents($url);
        if ($downloaded === false || $downloaded === '') {
            return $this->json(['error' => 1]);
        }

        $purchase = $this->purchases->find($id);
        $file = $this->findFile($purchase, $fileId);
        if ($file === null || !$purchase instanceof PurchaseRequest) {
            return $this->json(['error' => 0]);
        }

        $this->storage->replace($file->getStorageKey(), $downloaded);
        $this->markAck($fileId, $saveId);

        $actor = $this->em->find(User::class, $session['uid']);
        if ($actor instanceof User) {
            $this->editor->log(
                $purchase,
                $actor,
                PurchaseHistoryAction::FILE_EDITED,
                sprintf('%s: %s', $file->getType()->getLabel(), (string) $file->getOriginalName()),
            );
        }

        return $this->json(['error' => 0]);
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
        if ($key === '' || !$this->tokenMatches($purchaseId, $fileId, (int) $user->getId(), $key, $mode, $token)) {
            return null;
        }

        return ['key' => $key, 'mode' => $mode];
    }

    /** @return array{uid: int, key: string, mode: string}|null */
    private function sessionFromQuery(int $purchaseId, int $fileId, Request $request): ?array
    {
        $uid = (int) $request->query->get('uid');
        $key = (string) $request->query->get('key', '');
        $mode = (string) $request->query->get('mode', '');
        $token = (string) $request->query->get('token', '');
        if ($uid <= 0 || !$this->tokenMatches($purchaseId, $fileId, $uid, $key, $mode, $token)) {
            return null;
        }

        return ['uid' => $uid, 'key' => $key, 'mode' => $mode];
    }

    private function tokenMatches(int $purchaseId, int $fileId, int $userId, string $key, string $mode, string $token): bool
    {
        if ($key === '' || $token === '' || !in_array($mode, ['edit', 'view'], true)) {
            return false;
        }

        return hash_equals($this->sign($purchaseId, $fileId, $userId, $key, $mode), $token);
    }

    private function sign(int $purchaseId, int $fileId, int $userId, string $key, string $mode): string
    {
        return hash_hmac('sha256', $purchaseId . "\n" . $fileId . "\n" . $userId . "\n" . $key . "\n" . $mode, $this->appSecret);
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
