<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\FileGroup;

use App\Controller\SpaApi\Purchase\PurchaseFileEditorController;
use App\Controller\SpaApi\SpaApiError;
use App\Entity\FileGroup\FileGroup;
use App\Entity\FileGroup\FileGroupFile;
use App\Entity\User\User;
use App\Service\FileGroup\FileGroupAccess;
use App\Service\FileGroup\FileGroupEditorSession;
use App\Service\FileGroup\FileGroupStorage;
use App\Service\OnlyOffice\EditorSessionKey;
use App\Service\OnlyOffice\OnlyOfficeJwt;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * docx, xlsx, pptx и pdf группы в OnlyOffice. Чтение — у участника, запись — у создателя и тех, кому он её выдал.
 * Несколько редакторов делят один ключ сессии. Картинки в редактор не открываются.
 */
final class FileGroupEditorController extends AbstractController
{
    private const INTERNAL_APP = 'http://nginx_web';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FileGroupAccess $access,
        private readonly FileGroupStorage $storage,
        private readonly FileGroupEditorSession $sessions,
        private readonly OnlyOfficeJwt $jwt,
        #[Autowire('%onlyoffice_document_server_url%')]
        private readonly string $onlyofficeDocumentServerUrl,
    ) {
    }

    #[Route('/spa/api/file-groups/{groupId}/files/{fileId}/editor', name: 'spa_api_file_group_editor_open', requirements: ['groupId' => '\d+', 'fileId' => '\d+'], methods: ['POST'])]
    public function open(int $groupId, int $fileId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $found = $this->findFile($groupId, $fileId, $user);
        if ($found instanceof JsonResponse) {
            return $found;
        }
        [$file, $user] = $found;
        $edit = $this->access->canWrite($file, $user);
        $opened = $this->sessions->open($fileId, $edit, EditorSessionKey::wantsFresh($request->getContent()));
        $key = $opened['key'];
        $base = sprintf('%s/file_group_editor/%d/%d', self::INTERNAL_APP, $groupId, $fileId);
        $signed = fn (string $purpose): string => sprintf('%s/%s?%s', $base, $purpose, http_build_query([
            'key' => $key,
            'token' => $this->sessions->signFile($purpose, $groupId, $fileId, $key),
        ]));
        $mode = $edit ? 'edit' : 'view';
        $config = $this->editorConfig($file, $user, $edit, $signed('content'), $opened['join'] ? $signed('callback') : null, $key);
        if ($this->jwt->isEnabled()) {
            $config['token'] = $this->jwt->encode($config);
        }

        return $this->json([
            'mode' => $mode,
            'token' => $this->sessions->sign($groupId, $fileId, (int) $user->getId(), $key, $mode),
            'documentServerUrl' => rtrim($this->onlyofficeDocumentServerUrl, '/'),
            'config' => $config,
        ]);
    }

    #[Route('/spa/api/file-groups/{groupId}/files/{fileId}/editor', name: 'spa_api_file_group_editor_status', requirements: ['groupId' => '\d+', 'fileId' => '\d+'], methods: ['GET'])]
    public function status(int $groupId, int $fileId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $found = $this->findFile($groupId, $fileId, $user);
        if ($found instanceof JsonResponse) {
            return $found;
        }

        return $this->json([
            'saveAck' => $this->sessions->isAcked($fileId, (string) $request->query->get('saveId', '')),
        ]);
    }

    #[Route('/spa/api/file-groups/{groupId}/files/{fileId}/editor/save', name: 'spa_api_file_group_editor_save', requirements: ['groupId' => '\d+', 'fileId' => '\d+'], methods: ['POST'])]
    public function save(int $groupId, int $fileId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $found = $this->findFile($groupId, $fileId, $user);
        if ($found instanceof JsonResponse) {
            return $found;
        }
        [$file, $user] = $found;
        if (!$this->access->canWrite($file, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }
        $session = $this->sessionFromBody($groupId, $fileId, $user, $request);
        if ($session === null || $session['mode'] !== 'edit' || !$this->sessions->isCurrent($fileId, $session['key'])) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $saveId = bin2hex(random_bytes(8));
        $result = $this->sessions->forcesave($session['key'], sprintf('commit.%s.%d', $saveId, $user->getId()));
        $error = (int) ($result['error'] ?? 1);
        if ($error === 4) {
            $this->sessions->markAck($fileId, $saveId);

            return $this->json(['saveId' => $saveId, 'saveAck' => true]);
        }
        if ($error !== 0) {
            return $this->json(['error' => SpaApiError::FILE_GROUP_EDITOR_FAILED], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json(['saveId' => $saveId, 'saveAck' => false]);
    }

    #[Route('/file_group_editor/{groupId}/{fileId}/content', name: 'file_group_editor_content', requirements: ['groupId' => '\d+', 'fileId' => '\d+'], methods: ['GET'])]
    public function content(int $groupId, int $fileId, Request $request): Response
    {
        if ($this->keyFromQuery('content', $groupId, $fileId, $request) === null) {
            return $this->json(['error' => SpaApiError::FILE_GROUP_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        $file = $this->em->find(FileGroupFile::class, $fileId);
        $path = $file instanceof FileGroupFile && $file->getGroup()->getId() === $groupId
            ? $this->storage->path($file)
            : null;
        if ($path === null || !is_file($path)) {
            return $this->json(['error' => SpaApiError::FILE_GROUP_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', FileGroupStorage::mime($file->getStoredName()));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/file_group_editor/{groupId}/{fileId}/callback', name: 'file_group_editor_callback', requirements: ['groupId' => '\d+', 'fileId' => '\d+'], methods: ['POST'])]
    public function callback(int $groupId, int $fileId, Request $request): JsonResponse
    {
        $key = $this->keyFromQuery('callback', $groupId, $fileId, $request);
        $data = $key !== null ? $this->callbackData($request) : null;
        if ($data === null || ($data['key'] ?? null) !== $key) {
            return $this->json(['error' => 0]);
        }

        $status = (int) ($data['status'] ?? 0);
        if ($status === 2 || $status === 4) {
            $this->sessions->dropSession($fileId, $key);

            return $this->json(['error' => 0]);
        }
        if ($status !== 6) {
            return $this->json(['error' => 0]);
        }
        $saveId = self::parseSaveId((string) ($data['userdata'] ?? ''));
        if ($saveId === '' || $this->sessions->isAcked($fileId, $saveId)) {
            return $this->json(['error' => 0]);
        }

        $file = $this->em->find(FileGroupFile::class, $fileId);
        if (!$file instanceof FileGroupFile || $file->getGroup()->getId() !== $groupId || !$this->sessions->isCurrent($fileId, $key)) {
            return $this->json(['error' => 0]);
        }
        $url = PurchaseFileEditorController::internalDownloadUrl((string) ($data['url'] ?? ''), $this->onlyofficeDocumentServerUrl);
        if ($url === null) {
            return $this->json(['error' => 1]);
        }
        $downloaded = @file_get_contents($url);
        if ($downloaded === false || $downloaded === '' || !$this->writeFile($file, $downloaded)) {
            return $this->json(['error' => 1]);
        }
        $this->sessions->markSaved($fileId, $key);
        $this->sessions->markAck($fileId, $saveId);

        return $this->json(['error' => 0]);
    }

    private function writeFile(FileGroupFile $file, string $bytes): bool
    {
        if (strlen($bytes) > FileGroupStorage::MAX_BYTES) {
            return false;
        }
        $path = $this->storage->path($file);
        if (!is_file($path) || file_put_contents($path, $bytes) === false) {
            return false;
        }
        $file->setSize(strlen($bytes));
        $this->em->flush();

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function editorConfig(FileGroupFile $file, User $user, bool $edit, string $contentUrl, ?string $callbackUrl, string $key): array
    {
        $ext = FileGroupStorage::extension($file->getStoredName());
        $editorConfig = [
            'lang' => 'ru',
            'mode' => $edit ? 'edit' : 'view',
            'user' => ['id' => (string) $user->getId(), 'name' => $this->access->presentUser($user)['fullName']],
            'customization' => ['forcesave' => false],
            'coEditing' => ['mode' => 'fast', 'change' => false],
        ];
        if ($callbackUrl !== null) {
            $editorConfig['callbackUrl'] = $callbackUrl;
        }
        $permissions = $edit
            ? ['edit' => true, 'review' => false, 'comment' => true]
            : ['edit' => false, 'review' => false, 'comment' => false];
        if ($ext === 'xlsx' && $permissions['comment']) {
            $permissions['editCommentAuthorOnly'] = true;
            $permissions['deleteCommentAuthorOnly'] = true;
        }

        return [
            'width' => '100%',
            'height' => '100%',
            'type' => 'desktop',
            'documentType' => FileGroupStorage::documentType($file->getStoredName()) ?? 'word',
            'document' => [
                'fileType' => $ext,
                'key' => $key,
                'title' => $file->getOriginalName(),
                'url' => $contentUrl,
                'permissions' => $permissions,
            ],
            'editorConfig' => $editorConfig,
        ];
    }

    /**
     * @return array{0: FileGroupFile, 1: User}|JsonResponse
     */
    private function findFile(int $groupId, int $fileId, ?User $user): array|JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $group = $this->em->find(FileGroup::class, $groupId);
        $file = $this->em->find(FileGroupFile::class, $fileId);
        if (!$group instanceof FileGroup || !$file instanceof FileGroupFile || $file->getGroup()->getId() !== $group->getId() || !$this->access->canRead($group, $user)) {
            return $this->json(['error' => SpaApiError::FILE_GROUP_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        if (FileGroupStorage::documentType($file->getStoredName()) === null) {
            return $this->json(['error' => SpaApiError::FILE_GROUP_FILE_TYPE], Response::HTTP_BAD_REQUEST);
        }

        return [$file, $user];
    }

    /** @return array{key: string, mode: string}|null */
    private function sessionFromBody(int $groupId, int $fileId, User $user, Request $request): ?array
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
        if (!hash_equals($this->sessions->sign($groupId, $fileId, (int) $user->getId(), $key, $mode), $token)) {
            return null;
        }

        return ['key' => $key, 'mode' => $mode];
    }

    private function keyFromQuery(string $purpose, int $groupId, int $fileId, Request $request): ?string
    {
        $key = (string) $request->query->get('key', '');
        $token = (string) $request->query->get('token', '');
        if ($key === '' || $token === '' || !hash_equals($this->sessions->signFile($purpose, $groupId, $fileId, $key), $token)) {
            return null;
        }

        return $key;
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
}
