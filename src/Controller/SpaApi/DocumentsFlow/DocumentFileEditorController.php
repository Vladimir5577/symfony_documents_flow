<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Controller\SpaApi\Purchase\PurchaseFileEditorController;
use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\File;
use App\Entity\User\User;
use App\Repository\Document\DocumentRepository;
use App\Service\OnlyOffice\OnlyOfficeJwt;
use App\Service\SpaApi\Documents\DocumentAccessService;
use App\Service\SpaApi\Documents\DocumentAttachmentService;
use App\Service\SpaApi\Documents\DocumentNotifier;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Редактор docx и xlsx исходящего документа. Правит автор документа или администратор.
 *
 * В хранилище файл попадает только по «Сохранить»: команда forcesave, callback
 * со status 6 и userdata commit.{saveId}.{userId}. Пока ключ жив, следующее
 * открытие входит в ту же сессию — иначе вторая вкладка затирает его и
 * «Сохранить» в первой получает 403. Закрытие (status 2), когда в сессии уже
 * никого нет, ключ забывает: следующий заход не получит кэш правок.
 *
 * docx открывается в рецензировании: новая вставка и удаление остаются правкой
 * с ФИО, пока её не примут. Текст, набранный раньше, без автора. xlsx так не умеет:
 * быстрый режим без переключателя, зритель при живом ключе входит в ту же сессию.
 * Комментарий к ячейке меняет и удаляет только его автор.
 *
 * Браузер ходит в /spa/api с JWT. Document Server скачивает файл и шлёт callback
 * без JWT, поэтому эти адреса подписаны HMAC. Подпись Document Server
 * проверяется, если задан ONLYOFFICE_JWT_SECRET.
 */
final class DocumentFileEditorController extends AbstractController
{
    private const INTERNAL_APP = 'http://nginx_web';

    /** Тот же потолок, что у загрузки вложения. */
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /** @var array<string, string> */
    private const OFFICE_MIME = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** @var array<string, string> */
    private const OFFICE_TYPE = [
        'docx' => 'word',
        'xlsx' => 'cell',
    ];

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentAccessService $access,
        private readonly DocumentAttachmentService $attachments,
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

    #[Route('/spa/api/documents-flow/outgoing/{documentId}/office-file', name: 'spa_api_documents_flow_office_file_create', requirements: ['documentId' => '\d+'], methods: ['POST'])]
    public function create(int $documentId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $document = $this->findEditable($documentId, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        $format = $this->requestedFormat($request);
        if ($format === null) {
            return $this->json(['error' => SpaApiError::DOCUMENT_VALIDATION_FAILED], Response::HTTP_BAD_REQUEST);
        }

        $path = $format === 'xlsx' ? $this->blankXlsx() : $this->blankDocx();
        if ($path === null) {
            return $this->json(['error' => SpaApiError::DOCUMENT_FILE_EDITOR_FAILED], Response::HTTP_BAD_GATEWAY);
        }

        try {
            $uploaded = new UploadedFile(
                $path,
                $this->blankName($document, $format),
                self::OFFICE_MIME[$format],
                null,
                true,
            );
            $file = $this->attachments->upload($document, $uploaded);
        } catch (HttpException $e) {
            $message = $e->getMessage();

            return $this->json(
                ['error' => $message !== '' ? $message : SpaApiError::DOCUMENT_FILE_EDITOR_FAILED],
                $e->getStatusCode(),
            );
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        return $this->json($this->attachments->presentFile($document, $file), Response::HTTP_CREATED);
    }

    #[Route('/spa/api/documents-flow/outgoing/{documentId}/attachments/{fileId}/editor', name: 'spa_api_documents_flow_file_editor_open', requirements: ['documentId' => '\d+', 'fileId' => '\d+'], methods: ['POST'])]
    public function open(int $documentId, int $fileId, #[CurrentUser] ?User $user): JsonResponse
    {
        $found = $this->findOffice($documentId, $fileId, $user);
        if ($found instanceof JsonResponse) {
            return $found;
        }
        [$document, $file] = $found;
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $edit = $this->canEdit($document, $user);
        $stored = $this->storedKey($fileId);
        // xlsx-зритель входит в сессию редакторов: имя на ячейке есть только пока ключ жив.
        $joinLive = !$edit && $this->officeExt($file) === 'xlsx' && is_string($stored);
        $key = ($edit || $joinLive) ? ($stored ?? $this->newKey($fileId)) : $this->newKey($fileId);
        if ($edit) {
            $this->rememberKey($fileId, $key);
        }
        $base = sprintf('%s/document_file_editor/%d/%d', self::INTERNAL_APP, $document->getId(), $file->getId());
        $signed = fn (string $purpose): string => sprintf('%s/%s?%s', $base, $purpose, http_build_query([
            'key' => $key,
            'token' => $this->signFile($purpose, (int) $document->getId(), (int) $file->getId(), $key),
        ]));

        $config = $this->editorConfig(
            $document,
            $file,
            $user,
            $edit,
            $signed('content'),
            ($edit || $joinLive) ? $signed('callback') : null,
            $key,
        );
        if ($this->jwt->isEnabled()) {
            $config['token'] = $this->jwt->encode($config);
        }

        return $this->json([
            'mode' => $edit ? 'edit' : 'view',
            'token' => $this->sign((int) $document->getId(), (int) $file->getId(), (int) $user->getId(), $key, $edit ? 'edit' : 'view'),
            'documentServerUrl' => rtrim($this->onlyofficeDocumentServerUrl, '/'),
            'config' => $config,
        ]);
    }

    #[Route('/spa/api/documents-flow/outgoing/{documentId}/attachments/{fileId}/editor', name: 'spa_api_documents_flow_file_editor_status', requirements: ['documentId' => '\d+', 'fileId' => '\d+'], methods: ['GET'])]
    public function status(int $documentId, int $fileId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $found = $this->findOffice($documentId, $fileId, $user);
        if ($found instanceof JsonResponse) {
            return $found;
        }

        return $this->json([
            'saveAck' => $this->isAcked($fileId, (string) $request->query->get('saveId', '')),
        ]);
    }

    #[Route('/spa/api/documents-flow/outgoing/{documentId}/attachments/{fileId}/editor/save', name: 'spa_api_documents_flow_file_editor_save', requirements: ['documentId' => '\d+', 'fileId' => '\d+'], methods: ['POST'])]
    public function save(int $documentId, int $fileId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $found = $this->findOffice($documentId, $fileId, $user);
        if ($found instanceof JsonResponse) {
            return $found;
        }
        [$document] = $found;
        if (!$user instanceof User || !$this->canEdit($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
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

    #[Route('/document_file_editor/{documentId}/{fileId}/content', name: 'document_file_editor_content', requirements: ['documentId' => '\d+', 'fileId' => '\d+'], methods: ['GET'])]
    public function content(int $documentId, int $fileId, Request $request): Response
    {
        if ($this->keyFromQuery('content', $documentId, $fileId, $request) === null) {
            return $this->json(['error' => SpaApiError::ATTACHMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        $document = $this->documents->find($documentId);
        $file = $document instanceof Document ? $this->attachments->findForDocument($documentId, $fileId) : null;
        $path = $document instanceof Document && $file instanceof File
            ? $this->attachments->resolveFilePath($document, $file)
            : null;
        $ext = $this->officeExt($file);
        if ($path === null || $ext === null) {
            return $this->json(['error' => SpaApiError::ATTACHMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', self::OFFICE_MIME[$ext]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/document_file_editor/{documentId}/{fileId}/callback', name: 'document_file_editor_callback', requirements: ['documentId' => '\d+', 'fileId' => '\d+'], methods: ['POST'])]
    public function callback(int $documentId, int $fileId, Request $request): JsonResponse
    {
        $key = $this->keyFromQuery('callback', $documentId, $fileId, $request);
        $data = $key !== null ? $this->callbackData($request) : null;
        if ($data === null || ($data['key'] ?? null) !== $key) {
            return $this->json(['error' => 0]);
        }

        $status = (int) ($data['status'] ?? 0);
        // 2 — все вышли. Закрытие файл не пишет.
        if ($status === 2) {
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

        $document = $this->documents->find($documentId);
        $file = $document instanceof Document ? $this->attachments->findForDocument($documentId, $fileId) : null;
        if (!$document instanceof Document || !$file instanceof File || !$this->isCurrentKey($fileId, $key)) {
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
        $document->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->notifyEdited($document, (string) ($data['userdata'] ?? ''), $this->attachments->displayName($file));
        $this->markAck($fileId, $saveId);

        return $this->json(['error' => 0]);
    }

    private function dropSession(int $fileId, string $key): void
    {
        $stored = $this->readKey($fileId);
        if ($stored === null || !hash_equals($stored['key'], $key)) {
            return;
        }
        // ponytail: 20 с. Второй вход обновляет at, и status 2 от уже вышедшего не стирает ключ. Дольше окна опоздавший callback сотрёт ключ, «Сохранить» получит 403, пока не открыть снова.
        if ($stored['at'] > time() - 20) {
            return;
        }
        $this->cache->deleteItem($this->keyCacheId($fileId));
    }

    private function writeFile(Document $document, File $file, string $bytes): bool
    {
        if (strlen($bytes) > self::MAX_FILE_SIZE) {
            return false;
        }
        $path = $this->attachments->resolveFilePath($document, $file);
        if ($path === null) {
            return false;
        }

        return file_put_contents($path, $bytes) !== false;
    }

    /**
     * @return array<string, mixed>
     */
    private function editorConfig(Document $document, File $file, User $user, bool $edit, string $contentUrl, ?string $callbackUrl, string $key): array
    {
        $ext = $this->officeExt($file) ?? 'docx';
        // Рецензирование только у Word: в xlsx OnlyOffice не оставляет автора ячейки.
        $review = $edit && $ext === 'docx';
        $editorConfig = [
            'lang' => 'ru',
            'mode' => $edit ? 'edit' : 'view',
            'user' => ['id' => (string) $user->getId(), 'name' => $this->userName($user)],
            // Пишет файл только наша команда forcesave, не таймер Document Server.
            'customization' => ['forcesave' => false],
        ];
        if ($ext === 'xlsx') {
            // Строгий режим прячет чужие ячейки до своего Save, выбор запоминает браузер.
            $editorConfig['coEditing'] = ['mode' => 'fast', 'change' => false];
        }
        if ($ext === 'docx') {
            // edit: false — режим нельзя выключить, каждая новая правка с автором.
            // Принять в нём можно свою правку; чужую OnlyOffice не даёт.
            // trackChanges не шлём зрителю: false выключает рецензирование целиком.
            $reviewUi = [
                'reviewDisplay' => 'markup',
                'hoverMode' => true,
            ];
            if ($review) {
                $reviewUi['trackChanges'] = true;
            }
            $editorConfig['customization']['review'] = $reviewUi;
        }
        if ($callbackUrl !== null) {
            $editorConfig['callbackUrl'] = $callbackUrl;
        }

        $presented = $this->attachments->presentFile($document, $file);
        $permissions = match (true) {
            $review => ['edit' => false, 'review' => true, 'comment' => true],
            $edit => ['edit' => true, 'review' => false, 'comment' => true],
            default => ['edit' => false, 'review' => false, 'comment' => false],
        };
        if ($ext === 'xlsx' && $permissions['comment']) {
            $permissions['editCommentAuthorOnly'] = true;
            $permissions['deleteCommentAuthorOnly'] = true;
        }

        return [
            'width' => '100%',
            'height' => '100%',
            'type' => 'desktop',
            'documentType' => self::OFFICE_TYPE[$ext],
            'document' => [
                'fileType' => $ext,
                'key' => $key,
                'title' => (string) ($presented['filename'] ?? 'document.' . $ext),
                'url' => $contentUrl,
                'permissions' => $permissions,
            ],
            'editorConfig' => $editorConfig,
        ];
    }

    private function newKey(int $fileId): string
    {
        $base = sprintf('df%d-%s', $fileId, bin2hex(random_bytes(8)));

        return $base . '-' . substr(hash_hmac('sha256', 'document-file-key|' . $base, $this->appSecret), 0, 16);
    }

    private function rememberKey(int $fileId, string $key): void
    {
        // ponytail: cache.app, 24 ч. cache:clear или срок — «Сохранить» в уже открытой вкладке не пройдёт, пока не открыть снова. Колонка-счётчик, если ключ должен пережить сброс кэша.
        $item = $this->cache->getItem($this->keyCacheId($fileId));
        $item->set(['key' => $key, 'at' => time()]);
        $item->expiresAfter(86400);
        $this->cache->save($item);
    }

    private function storedKey(int $fileId): ?string
    {
        return $this->readKey($fileId)['key'] ?? null;
    }

    /**
     * @return array{key: string, at: int}|null
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
            return ['key' => $stored, 'at' => 0];
        }
        if (is_array($stored) && is_string($stored['key'] ?? null) && $stored['key'] !== '') {
            return ['key' => $stored['key'], 'at' => (int) ($stored['at'] ?? 0)];
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
        return 'document_file_editor_key_' . $fileId;
    }

    private function canEdit(Document $document, User $user): bool
    {
        return $this->access->canEditOutgoingDocument($document, $user);
    }

    /**
     * @return array{0: Document, 1: File}|JsonResponse
     */
    private function findOffice(int $documentId, int $fileId, ?User $user): array|JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $document = $this->findVisible($documentId, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }
        $file = $this->attachments->findForDocument($documentId, $fileId);
        if ($file === null) {
            return $this->json(['error' => SpaApiError::ATTACHMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        if ($this->officeExt($file) === null) {
            return $this->json(['error' => SpaApiError::DOCUMENT_FILE_NOT_DOCX], Response::HTTP_BAD_REQUEST);
        }

        return [$document, $file];
    }

    private function findEditable(int $documentId, ?User $user): Document|JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $document = $this->findVisible($documentId, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }
        if (!$this->canEdit($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        return $document;
    }

    private function findVisible(int $documentId, User $user): Document|JsonResponse
    {
        $document = $this->documents->findOneWithRelations($documentId);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        if (!$this->access->canViewDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        return $document;
    }

    private function officeExt(?File $file): ?string
    {
        if (!$file instanceof File) {
            return null;
        }
        $ext = strtolower(pathinfo((string) $file->getFilePath(), PATHINFO_EXTENSION));

        return isset(self::OFFICE_MIME[$ext]) ? $ext : null;
    }

    private function requestedFormat(Request $request): ?string
    {
        $raw = $request->getContent();
        if ($raw === '') {
            return 'docx';
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $format = (string) ($data['format'] ?? 'docx');

        return isset(self::OFFICE_MIME[$format]) ? $format : null;
    }

    private function blankDocx(): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        if ($path === false) {
            return null;
        }
        $target = $path . '.docx';
        if (!@rename($path, $target)) {
            @unlink($path);

            return null;
        }
        try {
            $phpWord = new PhpWord();
            $phpWord->addSection();
            IOFactory::createWriter($phpWord, 'Word2007')->save($target);
        } catch (\Throwable) {
            @unlink($target);

            return null;
        }

        return $target;
    }

    private function blankXlsx(): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($path === false) {
            return null;
        }
        $target = $path . '.xlsx';
        if (!@rename($path, $target)) {
            @unlink($path);

            return null;
        }
        try {
            $spreadsheet = new Spreadsheet();
            (new Xlsx($spreadsheet))->save($target);
            $spreadsheet->disconnectWorksheets();
        } catch (\Throwable) {
            @unlink($target);

            return null;
        }

        return $target;
    }

    private function blankName(Document $document, string $format): string
    {
        $title = trim(str_replace(["\r", "\n", '/', '\\', "\0"], ' ', (string) $document->getName()));
        $title = preg_replace('/\.(docx|xlsx)$/iu', '', $title) ?? $title;
        $title = trim($title);
        if ($title === '') {
            $title = 'Документ';
        }
        if (mb_strlen($title) > 180) {
            $title = mb_substr($title, 0, 180);
        }

        return $title . '.' . $format;
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

    private function notifyEdited(Document $document, string $userdata, string $fileName = ''): void
    {
        $userId = self::parseSaverId($userdata);
        $actor = $userId > 0 ? $this->em->find(User::class, $userId) : null;
        if (!$actor instanceof User) {
            return;
        }

        $loaded = $this->documents->findOneWithRelations((int) $document->getId()) ?? $document;
        $this->notifier->notifyEdited($loaded, $actor, fileName: $fileName);
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
        return hash_hmac('sha256', $documentId . "\n" . $fileId . "\n" . $userId . "\n" . $key . "\n" . $mode, $this->appSecret);
    }

    private function signFile(string $purpose, int $documentId, int $fileId, string $key): string
    {
        return hash_hmac('sha256', $purpose . "\n" . $documentId . "\n" . $fileId . "\n" . $key, $this->appSecret);
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
        return 'document_file_editor_' . $fileId . '_' . $saveId;
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
