<?php

namespace App\Controller\User;

use App\Entity\User\User;
use App\Entity\User\UserFile;
use App\Entity\User\UserFolderFile;
use App\Repository\User\UserFileRepository;
use App\Repository\User\UserFolderFileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class UserFileController extends AbstractController
{
    #[Route('/my_files', name: 'app_my_files', methods: ['GET'])]
    public function myFiles(
        UserFileRepository $userFileRepository,
        UserFolderFileRepository $userFolderFileRepository,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $folders = $userFolderFileRepository->findByUserAndParent($user, null);
        $files = $userFileRepository->findByUserAndFolder($user, null);
        $allFolders = $userFolderFileRepository->findAllByUser($user);

        $folderFileCounts = [];
        foreach ($allFolders as $folder) {
            $folderFileCounts[$folder->getId()] = $userFileRepository->countByFolder($folder);
        }

        return $this->render('user/my_files.html.twig', [
            'active_tab' => 'my_files',
            'currentFolder' => null,
            'breadcrumbs' => [],
            'folders' => $folders,
            'files' => $files,
            'folderFileCounts' => $folderFileCounts,
            'allFolders' => $allFolders,
        ]);
    }

    #[Route('/my_files/folder/{id}', name: 'app_my_files_folder', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function folder(
        int $id,
        UserFileRepository $userFileRepository,
        UserFolderFileRepository $userFolderFileRepository,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $currentFolder = $userFolderFileRepository->find($id);
        if (!$currentFolder instanceof UserFolderFile || $currentFolder->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Папка не найдена.');
        }

        $folders = $userFolderFileRepository->findByUserAndParent($user, $currentFolder);
        $files = $userFileRepository->findByUserAndFolder($user, $currentFolder);
        $breadcrumbs = $userFolderFileRepository->getBreadcrumbs($currentFolder);
        $allFolders = $userFolderFileRepository->findAllByUser($user);

        $folderFileCounts = [];
        foreach ($allFolders as $folder) {
            $folderFileCounts[$folder->getId()] = $userFileRepository->countByFolder($folder);
        }

        return $this->render('user/my_files.html.twig', [
            'active_tab' => 'my_files',
            'currentFolder' => $currentFolder,
            'breadcrumbs' => $breadcrumbs,
            'folders' => $folders,
            'files' => $files,
            'folderFileCounts' => $folderFileCounts,
            'allFolders' => $allFolders,
        ]);
    }

    #[Route('/my_files/folder/create', name: 'app_my_files_folder_create', methods: ['POST'])]
    public function createFolder(
        Request $request,
        UserFolderFileRepository $userFolderFileRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('my_files_folder_create', $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Неверный токен. Попробуйте снова.');
            return $this->redirectToRoute('app_my_files', [], Response::HTTP_SEE_OTHER);
        }

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash('error', 'Название папки не может быть пустым.');
            return $this->redirectToRoute('app_my_files', [], Response::HTTP_SEE_OTHER);
        }

        $parentId = (int) $request->request->get('parent_id', 0);
        $parent = null;
        if ($parentId > 0) {
            $parent = $userFolderFileRepository->find($parentId);
            if (!$parent instanceof UserFolderFile || $parent->getUser()?->getId() !== $user->getId()) {
                $this->addFlash('error', 'Родительская папка не найдена.');
                return $this->redirectToRoute('app_my_files', [], Response::HTTP_SEE_OTHER);
            }
        }

        $folder = new UserFolderFile();
        $folder->setUser($user);
        $folder->setName($name);
        $folder->setParent($parent);

        $entityManager->persist($folder);
        $entityManager->flush();

        $this->addFlash('success', 'Папка создана.');

        if ($parent !== null) {
            return $this->redirectToRoute('app_my_files_folder', ['id' => $parent->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('app_my_files', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/my_files/folder/{id}/rename', name: 'app_my_files_folder_rename', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function renameFolder(
        int $id,
        Request $request,
        UserFolderFileRepository $userFolderFileRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $folder = $userFolderFileRepository->find($id);
        if (!$folder instanceof UserFolderFile || $folder->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Папка не найдена.');
        }

        if (!$this->isCsrfTokenValid('my_files_folder_rename_' . $id, $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Неверный токен. Попробуйте снова.');
            return $this->redirectBackToFolder($folder->getParent());
        }

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash('error', 'Название папки не может быть пустым.');
            return $this->redirectBackToFolder($folder->getParent());
        }

        $folder->setName($name);
        $entityManager->flush();

        $this->addFlash('success', 'Папка переименована.');

        return $this->redirectBackToFolder($folder->getParent());
    }

    #[Route('/my_files/folder/{id}/delete', name: 'app_my_files_folder_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteFolder(
        int $id,
        Request $request,
        UserFolderFileRepository $userFolderFileRepository,
        UserFileRepository $userFileRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $folder = $userFolderFileRepository->find($id);
        if (!$folder instanceof UserFolderFile || $folder->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Папка не найдена.');
        }

        if (!$this->isCsrfTokenValid('my_files_folder_delete_' . $id, $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Неверный токен. Попробуйте снова.');
            return $this->redirectBackToFolder($folder->getParent());
        }

        $parentFolder = $folder->getParent();

        $this->deleteFolderRecursive($folder, $userFolderFileRepository, $userFileRepository, $entityManager);
        $entityManager->flush();

        $this->addFlash('success', 'Папка удалена.');

        return $this->redirectBackToFolder($parentFolder);
    }

    private function deleteFolderRecursive(
        UserFolderFile $folder,
        UserFolderFileRepository $userFolderFileRepository,
        UserFileRepository $userFileRepository,
        EntityManagerInterface $entityManager,
    ): void {
        $childFolders = $userFolderFileRepository->findBy(['parent' => $folder]);
        foreach ($childFolders as $child) {
            $this->deleteFolderRecursive($child, $userFolderFileRepository, $userFileRepository, $entityManager);
        }

        $files = $userFileRepository->findBy(['folder' => $folder]);
        foreach ($files as $file) {
            $entityManager->remove($file);
        }

        $entityManager->remove($folder);
    }

    #[Route('/my_files/folder/{id}/move', name: 'app_my_files_folder_move', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function moveFolder(
        int $id,
        Request $request,
        UserFolderFileRepository $userFolderFileRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $folder = $userFolderFileRepository->find($id);
        if (!$folder instanceof UserFolderFile || $folder->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Папка не найдена.');
        }

        if (!$this->isCsrfTokenValid('my_files_move_' . $id, $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Неверный токен. Попробуйте снова.');
            return $this->redirectBackToFolder($folder->getParent());
        }

        $targetFolderId = $request->request->get('target_folder_id', '');
        $targetFolder = null;

        if ($targetFolderId !== '' && $targetFolderId !== '0') {
            $targetFolder = $userFolderFileRepository->find((int) $targetFolderId);
            if (!$targetFolder instanceof UserFolderFile || $targetFolder->getUser()?->getId() !== $user->getId()) {
                $this->addFlash('error', 'Целевая папка не найдена.');
                return $this->redirectBackToFolder($folder->getParent());
            }

            if ($targetFolder->getId() === $folder->getId()) {
                $this->addFlash('error', 'Нельзя переместить папку в саму себя.');
                return $this->redirectBackToFolder($folder->getParent());
            }

            $descendantIds = $userFolderFileRepository->getDescendantIds($folder);
            if (in_array($targetFolder->getId(), $descendantIds, true)) {
                $this->addFlash('error', 'Нельзя переместить папку в свою подпапку.');
                return $this->redirectBackToFolder($folder->getParent());
            }
        }

        $previousParent = $folder->getParent();
        $folder->setParent($targetFolder);
        $entityManager->flush();

        $this->addFlash('success', 'Папка перемещена.');

        return $this->redirectBackToFolder($previousParent);
    }

    #[Route('/my_files/file/{id}/rename', name: 'app_my_files_file_rename', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function renameFile(
        int $id,
        Request $request,
        UserFileRepository $userFileRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $file = $userFileRepository->find($id);
        if (!$file instanceof UserFile || $file->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Файл не найден.');
        }

        if (!$this->isCsrfTokenValid('my_files_rename_' . $id, $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Неверный токен. Попробуйте снова.');
            return $this->redirectBackToFolder($file->getFolder());
        }

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash('error', 'Название файла не может быть пустым.');
            return $this->redirectBackToFolder($file->getFolder());
        }

        $file->setTitle($name);
        $entityManager->flush();

        $this->addFlash('success', 'Файл переименован.');

        return $this->redirectBackToFolder($file->getFolder());
    }

    #[Route('/my_files/file/{id}/move', name: 'app_my_files_file_move', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function moveFile(
        int $id,
        Request $request,
        UserFileRepository $userFileRepository,
        UserFolderFileRepository $userFolderFileRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $file = $userFileRepository->find($id);
        if (!$file instanceof UserFile || $file->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Файл не найден.');
        }

        if (!$this->isCsrfTokenValid('my_files_move_' . $id, $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Неверный токен. Попробуйте снова.');
            return $this->redirectBackToFolder($file->getFolder());
        }

        $targetFolderId = $request->request->get('target_folder_id', '');
        $targetFolder = null;

        if ($targetFolderId !== '' && $targetFolderId !== '0') {
            $targetFolder = $userFolderFileRepository->find((int) $targetFolderId);
            if (!$targetFolder instanceof UserFolderFile || $targetFolder->getUser()?->getId() !== $user->getId()) {
                $this->addFlash('error', 'Целевая папка не найдена.');
                return $this->redirectBackToFolder($file->getFolder());
            }
        }

        $previousFolder = $file->getFolder();
        $file->setFolder($targetFolder);
        $entityManager->flush();

        $this->addFlash('success', 'Файл перемещён.');

        return $this->redirectBackToFolder($previousFolder);
    }

    #[Route('/my_files/upload-single', name: 'app_my_files_upload_single', methods: ['POST'])]
    public function uploadSingle(
        Request $request,
        UserFolderFileRepository $userFolderFileRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Не авторизован.'], Response::HTTP_UNAUTHORIZED);
        }

        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile instanceof UploadedFile) {
            return new JsonResponse(['error' => 'Файл не загружен.'], Response::HTTP_BAD_REQUEST);
        }

        if ($uploadedFile->getError() !== \UPLOAD_ERR_OK) {
            return new JsonResponse(['error' => 'Ошибка загрузки файла.'], Response::HTTP_BAD_REQUEST);
        }

        $folderId = (int) $request->request->get('folder_id', 0);
        $folder = null;
        if ($folderId > 0) {
            $folder = $userFolderFileRepository->find($folderId);
            if (!$folder instanceof UserFolderFile || $folder->getUser()?->getId() !== $user->getId()) {
                return new JsonResponse(['error' => 'Папка не найдена.'], Response::HTTP_BAD_REQUEST);
            }
        }

        $userFile = new UserFile();
        $userFile->setUser($user);
        $userFile->setFolder($folder);
        $userFile->setFile($uploadedFile);
        $userFile->setOriginalName($uploadedFile->getClientOriginalName());
        $userFile->setTitle(pathinfo($uploadedFile->getClientOriginalName(), \PATHINFO_FILENAME));
        $userFile->setFileSize((string) $uploadedFile->getSize());

        $entityManager->persist($userFile);
        $entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'id' => $userFile->getId(),
            'title' => $userFile->getTitle(),
            'originalName' => $userFile->getOriginalName(),
        ]);
    }

    #[Route('/my_files/search', name: 'app_my_files_search', methods: ['GET'])]
    public function search(
        Request $request,
        UserFileRepository $userFileRepository,
        UserFolderFileRepository $userFolderFileRepository,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Не авторизован.'], Response::HTTP_UNAUTHORIZED);
        }

        $query = trim((string) $request->query->get('q', ''));
        if ($query === '') {
            return new JsonResponse(['results' => []]);
        }

        $files = $userFileRepository->searchByUser($user, $query);
        $results = [];

        foreach ($files as $file) {
            $folder = $file->getFolder();
            $path = [];
            if ($folder !== null) {
                $breadcrumbs = $userFolderFileRepository->getBreadcrumbs($folder);
                foreach ($breadcrumbs as $bc) {
                    $path[] = [
                        'id' => $bc->getId(),
                        'name' => $bc->getName(),
                    ];
                }
            }

            $results[] = [
                'id' => $file->getId(),
                'title' => $file->getTitle() ?: $file->getOriginalName() ?: 'Без названия',
                'originalName' => $file->getOriginalName(),
                'fileSize' => $file->getFileSize(),
                'folderId' => $folder?->getId(),
                'path' => $path,
            ];
        }

        return new JsonResponse(['results' => $results]);
    }

    #[Route('/my_files/folder/{id}/info', name: 'app_my_files_folder_info', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function folderInfo(
        int $id,
        UserFolderFileRepository $userFolderFileRepository,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Не авторизован.'], Response::HTTP_UNAUTHORIZED);
        }

        $folder = $userFolderFileRepository->find($id);
        if (!$folder instanceof UserFolderFile || $folder->getUser()?->getId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Папка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $counts = $userFolderFileRepository->countContentsRecursive($folder);

        return new JsonResponse([
            'name' => $folder->getName(),
            'folders' => $counts['folders'],
            'files' => $counts['files'],
        ]);
    }

    #[Route('/my_files/download/{id}', name: 'app_my_files_download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function download(
        int $id,
        Request $request,
        UserFileRepository $userFileRepository,
        #[Autowire('%private_upload_dir_users%')] string $usersUploadDir,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $userFile = $userFileRepository->find($id);
        if (!$userFile instanceof UserFile || $userFile->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Файл не найден.');
        }

        $filePath = $userFile->getFilePath();
        if (!$filePath) {
            throw $this->createNotFoundException('Файл не прикреплён.');
        }

        $userId = $userFile->getUser()->getId();
        $absolutePath = str_contains($filePath, '/')
            ? $usersUploadDir . \DIRECTORY_SEPARATOR . $filePath
            : $usersUploadDir . \DIRECTORY_SEPARATOR . $userId . \DIRECTORY_SEPARATOR . $filePath;

        if (!is_file($absolutePath)) {
            throw $this->createNotFoundException('Файл не найден на диске.');
        }

        $filename = $userFile->getOriginalName() ?: $userFile->getTitle() ?: basename($filePath);
        $response = new BinaryFileResponse($absolutePath);
        $response->setContentDisposition(
            $request->query->getBoolean('inline') ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );

        return $response;
    }

    #[Route('/my_files/delete/{id}', name: 'app_my_files_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(
        int $id,
        Request $request,
        UserFileRepository $userFileRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $userFile = $userFileRepository->find($id);
        if (!$userFile instanceof UserFile || $userFile->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Файл не найден.');
        }

        $csrfToken = 'my_files_delete_' . $id;
        if (!$this->isCsrfTokenValid($csrfToken, $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Неверный токен. Попробуйте снова.');
            return $this->redirectBackToFolder($userFile->getFolder());
        }

        $folder = $userFile->getFolder();
        $entityManager->remove($userFile);
        $entityManager->flush();

        $this->addFlash('success', 'Файл удалён.');

        return $this->redirectBackToFolder($folder);
    }

    private function redirectBackToFolder(?UserFolderFile $folder): Response
    {
        if ($folder !== null) {
            return $this->redirectToRoute('app_my_files_folder', ['id' => $folder->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('app_my_files', [], Response::HTTP_SEE_OTHER);
    }
}
