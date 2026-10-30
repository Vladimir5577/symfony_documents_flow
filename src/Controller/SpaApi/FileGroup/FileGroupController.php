<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\FileGroup;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\FileGroup\FileGroup;
use App\Entity\FileGroup\FileGroupFile;
use App\Entity\FileGroup\FileGroupFolder;
use App\Entity\User\User;
use App\Service\FileGroup\FileGroupAccess;
use App\Service\FileGroup\FileGroupEditorSession;
use App\Service\FileGroup\FileGroupStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/file-groups')]
final class FileGroupController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FileGroupAccess $access,
        private readonly FileGroupStorage $storage,
        private readonly FileGroupEditorSession $sessions,
    ) {
    }

    #[Route('', name: 'spa_api_file_groups_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->user($user);
        /** @var list<FileGroup> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('g', 'm')
            ->from(FileGroup::class, 'g')
            ->leftJoin('g.members', 'm')
            ->where('g.owner = :user OR m = :user')
            ->setParameter('user', $user)
            ->orderBy('g.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
        $groups = [];
        foreach ($rows as $group) {
            $groups[(int) $group->getId()] = $this->summary($group, $user);
        }

        return $this->json(['groups' => array_values($groups)]);
    }

    #[Route('', name: 'spa_api_file_groups_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->user($user);
        $body = $this->jsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $name = $this->cleanName($body['name'] ?? null);
        if ($name === null) {
            return $this->json(['error' => SpaApiError::FILE_GROUP_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }
        $group = new FileGroup($user, $name);
        $this->em->persist($group);
        $this->em->flush();

        return $this->json(['group' => $this->summary($group, $user)], Response::HTTP_CREATED);
    }

    #[Route('/{groupId}', name: 'spa_api_file_groups_show', requirements: ['groupId' => '\d+'], methods: ['GET'])]
    public function show(int $groupId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->user($user);
        $group = $this->readable($groupId, $user);
        if ($group instanceof JsonResponse) {
            return $group;
        }

        return $this->respond($group, $user, $request->query->getInt('folderId'));
    }

    #[Route('/{groupId}', name: 'spa_api_file_groups_rename', requirements: ['groupId' => '\d+'], methods: ['PATCH'])]
    public function rename(int $groupId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->user($user);
        $group = $this->manageable($groupId, $user);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        $body = $this->jsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $name = $this->cleanName($body['name'] ?? null);
        if ($name === null) {
            return $this->json(['error' => SpaApiError::FILE_GROUP_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }
        $group->setName($name);
        $this->em->flush();

        return $this->json(['group' => $this->summary($group, $user)]);
    }

    #[Route('/{groupId}', name: 'spa_api_file_groups_delete', requirements: ['groupId' => '\d+'], methods: ['DELETE'])]
    public function delete(int $groupId, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->user($user);
        $group = $this->manageable($groupId, $user);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        $paths = [];
        foreach ($group->getFiles() as $file) {
            $paths[] = $this->storage->path($file);
        }
        $dir = $this->storage->directory($group);
        $this->em->remove($group);
        $this->em->flush();
        $this->storage->unlinkAll($paths, $dir);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{groupId}/members', name: 'spa_api_file_groups_member_add', requirements: ['groupId' => '\d+'], methods: ['POST'])]
    public function addMember(int $groupId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->user($user);
        $group = $this->manageable($groupId, $user);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        $body = $this->jsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $member = $this->em->find(User::class, (int) ($body['userId'] ?? 0));
        if (!$member instanceof User) {
            return $this->json(['error' => SpaApiError::USER_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        if ($this->access->isOwner($group, $member)) {
            return $this->json(['error' => SpaApiError::FILE_GROUP_OWNER_NOT_MEMBER], Response::HTTP_BAD_REQUEST);
        }
        $group->addMember($member);
        foreach ($group->getFiles() as $file) {
            if ($file->getUploadedBy()?->getId() === $member->getId()) {
                $file->addWriter($member);
            }
        }
        $this->em->flush();

        return $this->respond($group, $user, (int) ($body['folderId'] ?? 0));
    }

    #[Route('/{groupId}/members/{userId}', name: 'spa_api_file_groups_member_remove', requirements: ['groupId' => '\d+', 'userId' => '\d+'], methods: ['DELETE'])]
    public function removeMember(int $groupId, int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->user($user);
        $group = $this->manageable($groupId, $user);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        $member = $this->member($group, $userId);
        if (!$member instanceof User) {
            return $this->json(['error' => SpaApiError::FILE_GROUP_MEMBER_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        // ponytail: drop по каждому файлу группы. Один вызов на файл, пока файлов в группе немного.
        foreach ($group->getFiles() as $file) {
            $file->removeWriter($member);
            $this->sessions->dropUser((int) $file->getId(), $userId);
        }
        $group->removeMember($member);
        $this->em->flush();

        return $this->respond($group, $user, $request->query->getInt('folderId'));
    }

    #[Route('/{groupId}/members/{userId}', name: 'spa_api_file_groups_member_upload', requirements: ['groupId' => '\d+', 'userId' => '\d+'], methods: ['PATCH'])]
    public function memberUpload(int $groupId, int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->user($user);
        $group = $this->manageable($groupId, $user);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        $member = $this->member($group, $userId);
        if (!$member instanceof User) {
            return $this->json(['error' => SpaApiError::FILE_GROUP_MEMBER_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        $body = $this->jsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        if (!is_bool($body['canUpload'] ?? null)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }
        if ($body['canUpload']) {
            $group->addUploader($member);
        } else {
            $group->removeUploader($member);
        }
        $this->em->flush();

        return $this->respond($group, $user, (int) ($body['folderId'] ?? 0));
    }

    #[Route('/{groupId}/folders', name: 'spa_api_file_groups_folder_create', requirements: ['groupId' => '\d+'], methods: ['POST'])]
    public function createFolder(int $groupId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->user($user);
        $group = $this->manageable($groupId, $user);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        $body = $this->jsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $name = $this->cleanName($body['name'] ?? null);
        if ($name === null) {
            return $this->json(['error' => SpaApiError::FILE_GROUP_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }
        $parentId = (int) ($body['parentId'] ?? 0);
        $parent = null;
        if ($parentId > 0) {
            $found = $this->folderIn($group, $parentId);
            if ($found instanceof JsonResponse) {
                return $found;
            }
            $parent = $found;
        }
        $this->em->persist(new FileGroupFolder($group, $name, $parent));
        $this->em->flush();

        return $this->respond($group, $user, $parentId, Response::HTTP_CREATED);
    }

    #[Route('/{groupId}/folders/{folderId}/parent', name: 'spa_api_file_groups_folder_move', requirements: ['groupId' => '\d+', 'folderId' => '\d+'], methods: ['PATCH'])]
    public function moveFolder(int $groupId, int $folderId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->user($user);
        $group = $this->manageable($groupId, $user);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        $folder = $this->folderIn($group, $folderId);
        if ($folder instanceof JsonResponse) {
            return $folder;
        }
        $body = $this->jsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $parentId = (int) ($body['parentId'] ?? 0);
        $parent = null;
        if ($parentId > 0) {
            if ($parentId === $folderId) {
                return $this->json(['error' => SpaApiError::FILE_GROUP_FOLDER_LOOP], Response::HTTP_BAD_REQUEST);
            }
            $found = $this->folderIn($group, $parentId);
            if ($found instanceof JsonResponse) {
                return $found;
            }
            if (isset($this->folderIdsUnder($group, $folderId)[$parentId])) {
                return $this->json(['error' => SpaApiError::FILE_GROUP_FOLDER_LOOP], Response::HTTP_BAD_REQUEST);
            }
            $parent = $found;
        }
        $folder->setParent($parent);
        $this->em->flush();

        return $this->respond($group, $user, $parentId);
    }

    #[Route('/{groupId}/folders/{folderId}', name: 'spa_api_file_groups_folder_rename', requirements: ['groupId' => '\d+', 'folderId' => '\d+'], methods: ['PATCH'])]
    public function renameFolder(int $groupId, int $folderId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->user($user);
        $group = $this->manageable($groupId, $user);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        $folder = $this->folderIn($group, $folderId);
        if ($folder instanceof JsonResponse) {
            return $folder;
        }
        $body = $this->jsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $name = $this->cleanName($body['name'] ?? null);
        if ($name === null) {
            return $this->json(['error' => SpaApiError::FILE_GROUP_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }
        $folder->setName($name);
        $this->em->flush();

        return $this->respond($group, $user, (int) ($folder->getParent()?->getId() ?? 0));
    }

    #[Route('/{groupId}/folders/{folderId}', name: 'spa_api_file_groups_folder_delete', requirements: ['groupId' => '\d+', 'folderId' => '\d+'], methods: ['DELETE'])]
    public function deleteFolder(int $groupId, int $folderId, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->user($user);
        $group = $this->manageable($groupId, $user);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        $folder = $this->folderIn($group, $folderId);
        if ($folder instanceof JsonResponse) {
            return $folder;
        }
        $parentId = (int) ($folder->getParent()?->getId() ?? 0);
        $this->deleteFolderTree($group, $folder);

        return $this->respond($group, $user, $parentId);
    }

    #[Route('/{groupId}/files', name: 'spa_api_file_groups_file_upload', requirements: ['groupId' => '\d+'], methods: ['POST'])]
    public function upload(int $groupId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->user($user);
        $group = $this->readable($groupId, $user);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        if (!$this->access->canUpload($group, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }
        $upload = $request->files->get('file');
        if (!$upload instanceof UploadedFile || !$upload->isValid()) {
            return $this->json(['error' => SpaApiError::FILE_NOT_PROVIDED], Response::HTTP_BAD_REQUEST);
        }
        $name = $this->originalName($upload);
        if ($name === null) {
            return $this->json(['error' => SpaApiError::FILE_NOT_PROVIDED], Response::HTTP_BAD_REQUEST);
        }
        $size = $upload->getSize();
        if ($size === false || $size > FileGroupStorage::MAX_BYTES) {
            return $this->json(['error' => SpaApiError::FILE_GROUP_FILE_TOO_LARGE], Response::HTTP_BAD_REQUEST);
        }
        $rawFolder = $request->request->get('folderId');
        $folder = null;
        $folderId = 0;
        if ($rawFolder !== null && $rawFolder !== '') {
            $folderId = (int) $rawFolder;
            $found = $this->folderIn($group, $folderId);
            if ($found instanceof JsonResponse) {
                return $found;
            }
            $folder = $found;
        }
        try {
            $file = $this->storage->store($group, $upload, $name, $folder);
        } catch (\RuntimeException) {
            return $this->json(['error' => SpaApiError::FILE_GROUP_EDITOR_FAILED], Response::HTTP_BAD_GATEWAY);
        }
        if (!$this->access->isOwner($group, $user)) {
            $file->setUploadedBy($user);
            $file->addWriter($user);
        }
        $this->em->persist($file);
        $this->em->flush();

        return $this->respond($group, $user, $folderId, Response::HTTP_CREATED);
    }

    #[Route('/{groupId}/files/{fileId}', name: 'spa_api_file_groups_file_delete', requirements: ['groupId' => '\d+', 'fileId' => '\d+'], methods: ['DELETE'])]
    public function deleteFile(int $groupId, int $fileId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->user($user);
        $group = $this->manageable($groupId, $user);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        $file = $this->fileIn($group, $fileId);
        if ($file instanceof JsonResponse) {
            return $file;
        }
        $folderId = (int) ($file->getFolder()?->getId() ?? 0);
        $path = $this->storage->path($file);
        $this->em->remove($file);
        $this->em->flush();
        $this->storage->unlinkAll([$path]);

        return $this->respond($group, $user, $request->query->getInt('folderId') ?: $folderId);
    }

    #[Route('/{groupId}/files/{fileId}/folder', name: 'spa_api_file_groups_file_move', requirements: ['groupId' => '\d+', 'fileId' => '\d+'], methods: ['PATCH'])]
    public function moveFile(int $groupId, int $fileId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->user($user);
        $group = $this->manageable($groupId, $user);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        $file = $this->fileIn($group, $fileId);
        if ($file instanceof JsonResponse) {
            return $file;
        }
        $body = $this->jsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $folderId = (int) ($body['folderId'] ?? 0);
        $folder = null;
        if ($folderId > 0) {
            $found = $this->folderIn($group, $folderId);
            if ($found instanceof JsonResponse) {
                return $found;
            }
            $folder = $found;
        }
        $file->setFolder($folder);
        $this->em->flush();

        return $this->respond($group, $user, $folderId);
    }

    #[Route('/{groupId}/files/{fileId}/content', name: 'spa_api_file_groups_file_content', requirements: ['groupId' => '\d+', 'fileId' => '\d+'], methods: ['GET'])]
    public function content(int $groupId, int $fileId, #[CurrentUser] ?User $user): Response
    {
        $user = $this->user($user);
        $group = $this->readable($groupId, $user);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        $file = $this->fileIn($group, $fileId);
        if ($file instanceof JsonResponse) {
            return $file;
        }
        $path = $this->storage->path($file);
        if (!is_file($path)) {
            return $this->json(['error' => SpaApiError::FILE_GROUP_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }
        $response = new BinaryFileResponse($path);
        $ext = FileGroupStorage::extension($file->getStoredName());
        $response->headers->set('Content-Type', FileGroupStorage::mime($file->getStoredName()));
        $response->setContentDisposition(
            isset(FileGroupStorage::IMAGES[$ext]) ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $file->getOriginalName(),
        );

        return $response;
    }

    #[Route('/{groupId}/files/{fileId}/writers', name: 'spa_api_file_groups_file_writers', requirements: ['groupId' => '\d+', 'fileId' => '\d+'], methods: ['PUT'])]
    public function writers(int $groupId, int $fileId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->user($user);
        $group = $this->manageable($groupId, $user);
        if ($group instanceof JsonResponse) {
            return $group;
        }
        $file = $this->fileIn($group, $fileId);
        if ($file instanceof JsonResponse) {
            return $file;
        }
        $body = $this->jsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $ids = $body['userIds'] ?? null;
        if (!is_array($ids)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }
        $wanted = [];
        foreach ($ids as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
            }
            $memberId = (int) $id;
            if ($group->getOwner()->getId() === $memberId) {
                return $this->json(['error' => SpaApiError::FILE_GROUP_OWNER_NOT_MEMBER], Response::HTTP_BAD_REQUEST);
            }
            $member = $this->member($group, $memberId);
            if (!$member instanceof User) {
                return $this->json(['error' => SpaApiError::FILE_GROUP_MEMBER_NOT_FOUND], Response::HTTP_BAD_REQUEST);
            }
            $wanted[$memberId] = $member;
        }
        $author = $file->getUploadedBy();
        if ($author instanceof User) {
            $authorMember = $this->member($group, (int) $author->getId());
            if ($authorMember instanceof User) {
                $wanted[(int) $authorMember->getId()] = $authorMember;
            }
        }
        foreach ($file->getWriters()->toArray() as $writer) {
            $writerId = (int) $writer->getId();
            if (!isset($wanted[$writerId])) {
                $file->removeWriter($writer);
                $this->sessions->dropUser((int) $file->getId(), $writerId);
            }
        }
        foreach ($wanted as $member) {
            $file->addWriter($member);
        }
        $this->em->flush();

        return $this->respond($group, $user, $request->query->getInt('folderId') ?: (int) ($file->getFolder()?->getId() ?? 0));
    }

    private function user(?User $user): User
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function readable(int $groupId, User $user): FileGroup|JsonResponse
    {
        $group = $this->em->find(FileGroup::class, $groupId);
        if (!$group instanceof FileGroup || !$this->access->canRead($group, $user)) {
            return $this->json(['error' => SpaApiError::FILE_GROUP_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        return $group;
    }

    private function manageable(int $groupId, User $user): FileGroup|JsonResponse
    {
        $group = $this->readable($groupId, $user);
        if ($group instanceof JsonResponse || $this->access->canManage($group, $user)) {
            return $group;
        }

        return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
    }

    private function folderIn(FileGroup $group, int $folderId): FileGroupFolder|JsonResponse
    {
        $folder = $this->em->find(FileGroupFolder::class, $folderId);
        if (!$folder instanceof FileGroupFolder || $folder->getGroup()->getId() !== $group->getId()) {
            return $this->json(['error' => SpaApiError::FILE_GROUP_FOLDER_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        return $folder;
    }

    private function fileIn(FileGroup $group, int $fileId): FileGroupFile|JsonResponse
    {
        $file = $this->em->find(FileGroupFile::class, $fileId);
        if (!$file instanceof FileGroupFile || $file->getGroup()->getId() !== $group->getId()) {
            return $this->json(['error' => SpaApiError::FILE_GROUP_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        return $file;
    }

    private function member(FileGroup $group, int $userId): ?User
    {
        foreach ($group->getMembers() as $member) {
            if ($member->getId() === $userId) {
                return $member;
            }
        }

        return null;
    }

    private function respond(FileGroup $group, User $user, int $folderId, int $status = 200): JsonResponse
    {
        $payload = $this->detail($group, $user, $folderId);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        return $this->json($payload, $status);
    }

    /** @return array<string, mixed>|JsonResponse */
    private function detail(FileGroup $group, User $user, int $folderId): array|JsonResponse
    {
        $current = null;
        if ($folderId > 0) {
            $found = $this->folderIn($group, $folderId);
            if ($found instanceof JsonResponse) {
                return $found;
            }
            $current = $found;
        }
        // Дерево целиком: сайд-панель показывает структуру, а не только текущую папку.
        $folders = [];
        foreach ($group->getFolders() as $folder) {
            $folders[] = [
                'id' => (int) $folder->getId(),
                'name' => $folder->getName(),
                'parentId' => $folder->getParent() !== null ? (int) $folder->getParent()->getId() : null,
            ];
        }
        $files = [];
        foreach ($group->getFiles() as $file) {
            $writers = [];
            foreach ($file->getWriters() as $writer) {
                $writers[] = $this->access->presentUser($writer);
            }
            usort($writers, static fn (array $a, array $b): int => strcmp(mb_strtolower($a['fullName']), mb_strtolower($b['fullName'])));
            $author = $file->getUploadedBy();
            $files[] = [
                'id' => (int) $file->getId(),
                'name' => $file->getOriginalName(),
                'size' => $file->getSize(),
                'canWrite' => $this->access->canWrite($file, $user),
                'folderId' => $file->getFolder() !== null ? (int) $file->getFolder()->getId() : null,
                'authorId' => $author instanceof User ? (int) $author->getId() : null,
                'writers' => $writers,
            ];
        }
        $byName = static fn (array $a, array $b): int => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name']));
        usort($folders, $byName);
        usort($files, $byName);
        $members = [];
        foreach ($group->getMembers() as $member) {
            $row = $this->access->presentUser($member);
            $row['canUpload'] = $group->getUploaders()->contains($member);
            $members[] = $row;
        }
        usort($members, static fn (array $a, array $b): int => strcmp(mb_strtolower($a['fullName']), mb_strtolower($b['fullName'])));

        return [
            'group' => [
                'id' => (int) $group->getId(),
                'name' => $group->getName(),
                'isOwner' => $this->access->isOwner($group, $user),
                'canUpload' => $this->access->canUpload($group, $user),
                'owner' => $this->access->presentUser($group->getOwner()),
                'createdAt' => $group->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
            'folderId' => $current?->getId(),
            'breadcrumbs' => $this->breadcrumbs($current),
            'folders' => $folders,
            'files' => $files,
            'members' => $members,
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function breadcrumbs(?FileGroupFolder $folder): array
    {
        $chain = [];
        $seen = [];
        $current = $folder;
        while ($current instanceof FileGroupFolder) {
            $id = (int) $current->getId();
            if (isset($seen[$id]) || count($chain) > 32) {
                break;
            }
            $seen[$id] = true;
            array_unshift($chain, ['id' => $id, 'name' => $current->getName()]);
            $current = $current->getParent();
        }

        return $chain;
    }

    /** @return array{id: int, name: string, isOwner: bool, memberCount: int, createdAt: string} */
    private function summary(FileGroup $group, User $user): array
    {
        return [
            'id' => (int) $group->getId(),
            'name' => $group->getName(),
            'isOwner' => $this->access->isOwner($group, $user),
            'memberCount' => $group->getMembers()->count(),
            'createdAt' => $group->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function deleteFolderTree(FileGroup $group, FileGroupFolder $root): void
    {
        $ids = $this->folderIdsUnder($group, (int) $root->getId());
        $paths = [];
        foreach ($group->getFiles() as $file) {
            $folderId = $file->getFolder()?->getId();
            if ($folderId !== null && isset($ids[$folderId])) {
                $paths[] = $this->storage->path($file);
                $this->em->remove($file);
            }
        }
        $pending = [];
        foreach ($group->getFolders() as $folder) {
            $id = (int) $folder->getId();
            if (isset($ids[$id])) {
                $pending[$id] = $folder;
            }
        }
        $guard = 0;
        while ($pending !== [] && $guard < 1000) {
            ++$guard;
            $leaves = [];
            foreach ($pending as $id => $folder) {
                $hasChild = false;
                foreach ($pending as $otherId => $other) {
                    if ($otherId !== $id && $other->getParent()?->getId() === $id) {
                        $hasChild = true;
                        break;
                    }
                }
                if (!$hasChild) {
                    $leaves[$id] = $folder;
                }
            }
            if ($leaves === []) {
                break;
            }
            foreach ($leaves as $id => $folder) {
                $this->em->remove($folder);
                unset($pending[$id]);
            }
        }
        $this->em->flush();
        $this->storage->unlinkAll($paths);
    }

    /** @return array<int, true> */
    private function folderIdsUnder(FileGroup $group, int $rootId): array
    {
        $children = [];
        foreach ($group->getFolders() as $folder) {
            $parentId = (int) ($folder->getParent()?->getId() ?? 0);
            $children[$parentId][] = (int) $folder->getId();
        }
        $ids = [];
        $stack = [$rootId];
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($ids[$id])) {
                continue;
            }
            $ids[$id] = true;
            foreach ($children[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $ids;
    }

    /** @return array<string, mixed>|JsonResponse */
    private function jsonBody(Request $request): array|JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        return $data;
    }

    private function cleanName(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $name = trim($value);
        if ($name === '' || mb_strlen($name) > 255) {
            return null;
        }

        return $name;
    }

    private function originalName(UploadedFile $upload): ?string
    {
        $name = str_replace(["\0", '/', '\\', "\r", "\n"], '', $upload->getClientOriginalName());
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }
        if (mb_strlen($name) > 255) {
            $ext = FileGroupStorage::extension($name);
            if (strlen($ext) > 20) {
                $ext = '';
            }
            $suffix = $ext === '' ? '' : '.' . $ext;
            $name = mb_substr($name, 0, 255 - strlen($suffix)) . $suffix;
        }

        return $name;
    }
}
