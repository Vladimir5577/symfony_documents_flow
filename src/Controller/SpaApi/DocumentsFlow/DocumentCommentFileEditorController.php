<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Controller\SpaApi\Purchase\PurchaseFileEditorController;
use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentCommentFile;
use App\Entity\User\User;
use App\Repository\Document\DocumentCommentFileRepository;
use App\Repository\Document\DocumentRepository;
use App\Service\OnlyOffice\EditorSessionKey;
use App\Service\OnlyOffice\OnlyOfficeJwt;
use App\Service\SpaApi\Documents\DocumentAccessService;
use App\Service\SpaApi\Documents\DocumentCommentService;
use App\Service\SpaApi\Documents\DocumentNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Редактор docx, xlsx, pptx и pdf во вложении комментария. Правит автор комментария или администратор.
 *
 * В хранилище файл попадает только по «Сохранить»: команда forcesave, callback
 * со status 6 и userdata commit.{saveId}.{userId}. Пока в сессии кто-то есть,
 * следующее открытие входит в неё — иначе вторая вкладка затирает ключ и
 * «Сохранить» в первой получает 403. После записи тот же ключ новому заходу
 * нельзя: Document Server показывает «Версия изменилась». Закрытие (status 2
 * и 4) такой ключ забывает сразу. Несохранённую сессию моложе 20 с не трогает,
 * и повторное открытие не продлевает эти 20 с. Если сервер всё же отверг ключ,
 * редактор просит refresh — тогда ключ всегда новый, без диалога.
 * Успешная запись двигает updatedAt файла. Текст комментария от этого не помечается.
 *
 * docx открывается в рецензировании: новая вставка и удаление остаются правкой
 * с ФИО, пока её не примут. xlsx так не умеет: быстрый режим без переключателя.
 * Комментарий к ячейке меняет и удаляет только его автор.
 * pptx и pdf тоже без рецензирования, в быстром соавторстве.
 * pdf открывается в комментариях: правка текста — переключатель в шапке редактора.
 *
 * Браузер ходит в /spa/api с JWT. Document Server скачивает файл и шлёт callback
 * без JWT, поэтому эти адреса подписаны HMAC.
 */
final class DocumentCommentFileEditorController extends AbstractController
{
    private const INTERNAL_APP = 'http://nginx_web';

    /** Тот же потолок, что у загрузки файла в комментарий. */
    private const MAX_FILE_SIZE = 25 * 1024 * 1024;

    /** @var array<string, string> */
    private const OFFICE_MIME = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'pdf' => 'application/pdf',
    ];

    /** @var array<string, string> */
    private const OFFICE_TYPE = [
        'docx' => 'word',
        'xlsx' => 'cell',
        'pptx' => 'slide',
        'pdf' => 'pdf',
    ];

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentCommentFileRepository $commentFiles,
        private readonly DocumentAccessService $access,
        private readonly DocumentCommentService $comments,
        private readonly DocumentNotifier $notifier,
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

    #[Route('/spa/api/documents-flow/documents/{documentId}/comments/files/{fileId}/editor', name: 'spa_api_documents_flow_comment_file_editor_open', requirements: ['documentId' => '\d+', 'fileId' => '\d+'], methods: ['POST'])]
    public function open(int $documentId, int $fileId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $found = $this->findEditable($documentId, $fileId, $user);
        if ($found instanceof JsonResponse) {
            return $found;
        }
        [$document, $file] = $found;
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $stored = $this->readKey($fileId);
        $refresh = EditorSessionKey::wantsFresh($request->getContent());
        $busy = false;
        if (
            !$refresh
            && $stored !== null
            && ($stored['saved'] || $stored['at'] <= time() - EditorSessionKey::KEEP_SECONDS)
        ) {
            $busy = $this->sessionBusy($stored['key']);
        }
        $key = $refresh
            ? $this->newKey($fileId)
            : EditorSessionKey::next($stored, true, $busy, fn (): string => $this->newKey($fileId));
        $same = $stored !== null && hash_equals($stored['key'], $key);
        $this->rememberKey($fileId, $key, $same && $stored['saved'], $same && !$busy ? $stored['at'] : null);
        $base = sprintf('%s/document_comment_file_editor/%d/%d', self::INTERNAL_APP, $document->getId(), $file->getId());
        $signed = fn (string $purpose): string => sprintf('%s/%s?%s', $base, $purpose, http_build_query([
            'key' => $key,
            'token' => $this->signFile($purpose, (int) $document->getId(), (int) $file->getId(), $key),
        ]));

        $config = $this->editorConfig($file, $user, $signed('content'), $signed('callback'), $key);
        if ($this->jwt->isEnabled()) {
            $config['token'] = $this->jwt->encode($config);
        }

        return $this->json([
            'mode' => 'edit',
            'token' => $this->sign((int) $document->getId(), (int) $file->getId(), (int) $user->getId(), $key, 'edit'),
            'documentServerUrl' => rtrim($this->onlyofficeDocumentServerUrl, '/'),
            'config' => $config,
        ]);
    }

    #[Route('/spa/api/documents-flow/documents/{documentId}/comments/files/{fileId}/editor', name: 'spa_api_documents_flow_comment_file_editor_status', requirements: ['documentId' => '\d+', 'fileId' => '\d+'], methods: ['GET'])]
    public function status(int $documentId, int $fileId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $found = $this->findEditable($documentId, $fileId, $user);
        if ($found instanceof JsonResponse) {
            return $found;
        }

        return $this->json([
            'saveAck' => $this->isAcked($fileId, (string) $request->query->get('saveId', '')),
        ]);
    }

    #[Route('/spa/api/documents-flow/documents/{documentId}/comments/files/{fileId}/editor/save', name: 'spa_api_documents_flow_comment_file_editor_save', requirements: ['documentId' => '\d+', 'fileId' => '\d+'], methods: ['POST'])]
    public function save(int $documentId, int $fileId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $found = $this->findEditable($documentId, $fileId, $user);
        if ($found instanceof JsonResponse) {
            return $found;
        }
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $session = $this->sessionFromBody($documentId, $fileId, $user, $request);
        if ($session === null || $session['mode'] !== 'edit' || !$this->isCurrentKey($fileId, $session['key'])) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $saveId = bin2hex(random_bytes(8));
        $result = $this->command($session['key'], 'forcesave', sprintf('commit.%s.%d', $saveId, $user->getId()));
        $error = (int) ($result['error'] ?? 1);
        // 4 — документ не менялся, callback не придёт.
        if ($error === 4) {
            $this->markAck($fileId, $saveId);

            return $this->json(['saveId' => $saveId, 'saveAck' => true]);
        }
        if ($error !== 0) {
            return $this->json(['error' => SpaApiError::DOCUMENT_FILE_EDITOR_FAILED], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json(['saveId' => $saveId, 'saveAck' => false]);
    }

    #[Route('/document_comment_file_editor/{documentId}/{fileId}/content', name: 'document_comment_file_editor_content', requirements: ['documentId' => '\d+', 'fileId' => '\d+'], methods: ['GET'])]
    public function content(int $documentId, int $fileId, Request $request): Response
    {
        if ($this->keyFromQuery('content', $documentId, $fileId, $request) === null) {
            return $this->json(['error' => SpaApiError::COMMENT_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        $file = $this->commentFiles->find($fileId);
        $document = $file instanceof DocumentCommentFile ? $file->getComment()->getDocument() : null;
        if (!$file instanceof DocumentCommentFile || !$document instanceof Document || $document->getId() !== $documentId) {
            return $this->json(['error' => SpaApiError::COMMENT_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        $path = $this->comments->resolveCommentFilePath($document, $file);
        $ext = $this->officeExt($file);
        if ($path === null || $ext === null) {
            return $this->json(['error' => SpaApiError::COMMENT_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', self::OFFICE_MIME[$ext]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/document_comment_file_editor/{documentId}/{fileId}/callback', name: 'document_comment_file_editor_callback', requirements: ['documentId' => '\d+', 'fileId' => '\d+'], methods: ['POST'])]
    public function callback(int $documentId, int $fileId, Request $request): JsonResponse
    {
        $key = $this->keyFromQuery('callback', $documentId, $fileId, $request);
        $data = $key !== null ? $this->callbackData($request) : null;
        if ($data === null || ($data['key'] ?? null) !== $key) {
            return $this->json(['error' => 0]);
        }

        $status = (int) ($data['status'] ?? 0);
        // 2 — вышли с несохранёнными правками, 4 — без новых (в том числе сразу после forcesave). Файл не пишем.
        if ($status === 2 || $status === 4) {
            $this->dropSession($fileId, $key);

            return $this->json(['error' => 0]);
        }
        // 6 — только наша команда forcesave. Таймер OnlyOffice приходит без userdata.
        if ($status !== 6) {
            return $this->json(['error' => 0]);
        }
        $saveId = self::parseSaveId((string) ($data['userdata'] ?? ''));
        if ($saveId === '' || $this->isAcked($fileId, $saveId)) {
            return $this->json(['error' => 0]);
        }

        $file = $this->commentFiles->find($fileId);
        $document = $file instanceof DocumentCommentFile ? $file->getComment()->getDocument() : null;
        if (!$file instanceof DocumentCommentFile || !$document instanceof Document || $document->getId() !== $documentId || !$this->isCurrentKey($fileId, $key)) {
            return $this->json(['error' => 0]);
        }
        $url = PurchaseFileEditorController::internalDownloadUrl((string) ($data['url'] ?? ''), $this->onlyofficeDocumentServerUrl);
        if ($url === null) {
            return $this->json(['error' => 1]);
        }
        $downloaded = @file_get_contents($url);
        if ($downloaded === false || $downloaded === '' || !$this->writeFile($document, $file, $downloaded)) {
            return $this->json(['error' => 1]);
        }
        $file->setSizeBytes(strlen($downloaded));
        $file->markEdited();
        $this->em->flush();
        $this->notifyEdited($document, (string) ($data['userdata'] ?? ''), true, (string) ($file->getFilename() ?? ''));
        $this->markSaved($fileId, $key);
        $this->markAck($fileId, $saveId);

        return $this->json(['error' => 0]);
    }

    private function dropSession(int $fileId, string $key): void
    {
        if (!EditorSessionKey::forget($this->readKey($fileId), $key, time())) {
            return;
        }
        $this->cache->deleteItem($this->keyCacheId($fileId));
    }

    private function writeFile(Document $document, DocumentCommentFile $file, string $bytes): bool
    {
        if (strlen($bytes) > self::MAX_FILE_SIZE) {
            return false;
        }
        $path = $this->comments->resolveCommentFilePath($document, $file);
        if ($path === null) {
            return false;
        }

        return file_put_contents($path, $bytes) !== false;
    }

    /**
     * @return array<string, mixed>
     */
    private function editorConfig(DocumentCommentFile $file, User $user, string $contentUrl, string $callbackUrl, string $key): array
    {
        $ext = $this->officeExt($file) ?? 'docx';
        $review = $ext === 'docx';
        $customization = ['forcesave' => false];
        if ($review) {
            // edit: false — режим нельзя выключить, каждая новая правка с автором.
            // Принять в нём можно свою правку; чужую OnlyOffice не даёт.
            $customization['review'] = [
                'trackChanges' => true,
                'reviewDisplay' => 'markup',
                'hoverMode' => true,
            ];
        }
        $permissions = $review
            ? ['edit' => false, 'review' => true, 'comment' => true]
            : ['edit' => true, 'review' => false, 'comment' => true];
        if ($ext === 'xlsx') {
            $permissions['editCommentAuthorOnly'] = true;
            $permissions['deleteCommentAuthorOnly'] = true;
        }
        $editorConfig = [
            'lang' => 'ru',
            'mode' => 'edit',
            'callbackUrl' => $callbackUrl,
            'user' => ['id' => (string) $user->getId(), 'name' => $this->userName($user)],
            'customization' => $customization,
        ];
        if ($ext === 'xlsx' || $ext === 'pptx' || $ext === 'pdf') {
            // Строгий режим прячет чужие правки до своего Save, выбор запоминает браузер.
            $editorConfig['coEditing'] = ['mode' => 'fast', 'change' => false];
        }

        return [
            'width' => '100%',
            'height' => '100%',
            'type' => 'desktop',
            'documentType' => self::OFFICE_TYPE[$ext],
            'document' => [
                'fileType' => $ext,
                'key' => $key,
                'title' => $file->getFilename() ?: 'document.' . $ext,
                'url' => $contentUrl,
                'permissions' => $permissions,
            ],
            'editorConfig' => $editorConfig,
        ];
    }

    private function newKey(int $fileId): string
    {
        $base = sprintf('cf%d-%s', $fileId, bin2hex(random_bytes(8)));

        return $base . '-' . substr(hash_hmac('sha256', 'comment-file-key|' . $base, $this->appSecret), 0, 16);
    }

    private function rememberKey(int $fileId, string $key, bool $saved = false, ?int $at = null): void
    {
        // ponytail: cache.app, 24 ч. cache:clear или срок — «Сохранить» в уже открытой вкладке не пройдёт, пока не открыть снова. Колонка-счётчик, если ключ должен пережить сброс кэша.
        $item = $this->cache->getItem($this->keyCacheId($fileId));
        $item->set(['key' => $key, 'at' => $at ?? time(), 'saved' => $saved]);
        $item->expiresAfter(86400);
        $this->cache->save($item);
    }

    private function markSaved(int $fileId, string $key): void
    {
        $stored = $this->readKey($fileId);
        if ($stored === null || !hash_equals($stored['key'], $key)) {
            return;
        }
        $this->rememberKey($fileId, $key, true);
    }

    private function sessionBusy(string $key): bool
    {
        return EditorSessionKey::busy($this->command($key, 'info', ''));
    }

    private function storedKey(int $fileId): ?string
    {
        return $this->readKey($fileId)['key'] ?? null;
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
        // Раньше в кэше лежала строка. at = 0: закрытие такой сессии по-прежнему забывает ключ.
        if (is_string($stored) && $stored !== '') {
            return ['key' => $stored, 'at' => 0, 'saved' => false];
        }
        if (is_array($stored) && is_string($stored['key'] ?? null) && $stored['key'] !== '') {
            return [
                'key' => $stored['key'],
                'at' => (int) ($stored['at'] ?? 0),
                'saved' => ($stored['saved'] ?? false) === true,
            ];
        }

        return null;
    }

    private function isCurrentKey(int $fileId, string $key): bool
    {
        $stored = $this->storedKey($fileId);

        return is_string($stored) && hash_equals($stored, $key);
    }

    private function keyCacheId(int $fileId): string
    {
        return 'comment_file_editor_key_' . $fileId;
    }

    /**
     * @return array{0: Document, 1: DocumentCommentFile}|JsonResponse
     */
    private function findEditable(int $documentId, int $fileId, ?User $user): array|JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $document = $this->documents->findOneWithRelations($documentId);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        if (!$this->access->canViewDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }
        $file = $this->commentFiles->find($fileId);
        if (!$file instanceof DocumentCommentFile || $file->getComment()->getDocument()->getId() !== $documentId) {
            return $this->json(['error' => SpaApiError::COMMENT_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        if (!$this->access->canEditCommentFile($file->getComment(), $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }
        if ($this->officeExt($file) === null) {
            return $this->json(['error' => SpaApiError::DOCUMENT_FILE_NOT_DOCX], Response::HTTP_BAD_REQUEST);
        }

        return [$document, $file];
    }

    private function officeExt(DocumentCommentFile $file): ?string
    {
        foreach ([$file->getFilename(), $file->getStorageKey()] as $name) {
            $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
            if (isset(self::OFFICE_MIME[$ext])) {
                return $ext;
            }
        }

        return null;
    }

    private function userName(User $user): string
    {
        $name = trim(implode(' ', array_filter(
            [$user->getLastname(), $user->getFirstname(), $user->getPatronymic()],
            static fn (?string $part): bool => $part !== null && $part !== '',
        )));

        return $name !== '' ? $name : (string) $user->getLogin();
    }

    /**
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

        return is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;
    }

    private static function parseSaveId(string $userdata): string
    {
        if (preg_match('/^commit\.([a-f0-9]{16})\.\d+$/', $userdata, $m) !== 1) {
            return '';
        }

        return $m[1];
    }

    private static function parseSaverId(string $userdata): int
    {
        if (preg_match('/^commit\.[a-f0-9]{16}\.(\d+)$/', $userdata, $m) !== 1) {
            return 0;
        }

        return (int) $m[1];
    }

    private function notifyEdited(Document $document, string $userdata, bool $discussion = false, string $fileName = ''): void
    {
        $userId = self::parseSaverId($userdata);
        $actor = $userId > 0 ? $this->em->find(User::class, $userId) : null;
        if (!$actor instanceof User) {
            return;
        }

        $loaded = $this->documents->findOneWithRelations((int) $document->getId()) ?? $document;
        $this->notifier->notifyEdited($loaded, $actor, $discussion, $fileName);
    }

    /** @return array{key: string, mode: string}|null */
    private function sessionFromBody(int $documentId, int $fileId, User $user, Request $request): ?array
    {
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
        if (!hash_equals($this->sign($documentId, $fileId, (int) $user->getId(), $key, $mode), $token)) {
            return null;
        }

        return ['key' => $key, 'mode' => $mode];
    }

    private function keyFromQuery(string $purpose, int $documentId, int $fileId, Request $request): ?string
    {
        $key = (string) $request->query->get('key', '');
        $token = (string) $request->query->get('token', '');
        if ($key === '' || $token === '' || !hash_equals($this->signFile($purpose, $documentId, $fileId, $key), $token)) {
            return null;
        }

        return $key;
    }

    private function sign(int $documentId, int $fileId, int $userId, string $key, string $mode): string
    {
        return hash_hmac('sha256', 'comment' . "\n" . $documentId . "\n" . $fileId . "\n" . $userId . "\n" . $key . "\n" . $mode, $this->appSecret);
    }

    private function signFile(string $purpose, int $documentId, int $fileId, string $key): string
    {
        return hash_hmac('sha256', 'comment' . "\n" . $purpose . "\n" . $documentId . "\n" . $fileId . "\n" . $key, $this->appSecret);
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
        return 'comment_file_editor_' . $fileId . '_' . $saveId;
    }

    /** @return array<string, mixed> */
    private function command(string $key, string $command, string $userdata): array
    {
        $commandUrl = 'http://onlyoffice/command?shardkey=' . rawurlencode($key);
        $body = ['c' => $command, 'key' => $key, 'userdata' => $userdata];
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
