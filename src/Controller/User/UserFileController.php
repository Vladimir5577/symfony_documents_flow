<?php

namespace App\Controller\User;

use App\Entity\User\User;
use App\Entity\User\UserFile;
use App\Entity\User\UserFolderFile;
use App\Repository\User\UserFolderFileRepository;
use App\Repository\User\UserFileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class UserFileController extends AbstractController
{
    #[Route('/my_files', name: 'app_my_files', methods: ['GET'])]
    public function myFiles(
        Request $request,
        UserFileRepository $userFileRepository,
        UserFolderFileRepository $userFolderFileRepository,
    ): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $search = trim((string) $request->query->get('search', ''));
        $files = $userFileRepository->findByUser($user);
        $folders = $userFolderFileRepository->findBy(['user' => $user], ['name' => 'ASC']);

        $folderGroups = [];
        foreach ($files as $file) {
            $folder = $file->getFolder();
            $groupKey = $folder instanceof UserFolderFile ? 'folder_' . $folder->getId() : 'root';

            if (!isset($folderGroups[$groupKey])) {
                $folderGroups[$groupKey] = [
                    'key' => $groupKey,
                    'label' => $folder?->getName() ?? 'Корень',
                    'files' => [],
                ];
            }

            $folderGroups[$groupKey]['files'][] = $file;
        }

        uasort($folderGroups, static function (array $left, array $right): int {
            if ($left['key'] === 'root') {
                return -1;
            }
            if ($right['key'] === 'root') {
                return 1;
            }

            return strcasecmp((string) $left['label'], (string) $right['label']);
        });

        return $this->render('user/my_files.html.twig', [
            'active_tab' => 'my_files',
            'folder_groups' => array_values($folderGroups),
            'folders' => $folders,
            'search' => $search,
        ]);
    }

    #[Route('/my_files/change-folder/{id}', name: 'app_my_files_change_folder', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function changeFolder(
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

        $userFile = $userFileRepository->find($id);
        if (!$userFile instanceof UserFile || $userFile->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Файл не найден.');
        }

        $csrfToken = 'my_files_change_folder_' . $id;
        if (!$this->isCsrfTokenValid($csrfToken, $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Неверный токен. Попробуйте снова.');
            return $this->redirectToRoute('app_my_files', [], Response::HTTP_SEE_OTHER);
        }

        $folderId = (int) $request->request->get('folder_id', 0);
        $folder = null;
        if ($folderId > 0) {
            $folder = $userFolderFileRepository->find($folderId);
            if (!$folder instanceof UserFolderFile || $folder->getUser()?->getId() !== $user->getId()) {
                $this->addFlash('error', 'Папка не найдена.');
                return $this->redirectToRoute('app_my_files', [], Response::HTTP_SEE_OTHER);
            }
        }

        $userFile->setFolder($folder);
        $entityManager->flush();

        $this->addFlash('success', 'Папка файла изменена.');
        $searchRedirect = trim((string) $request->request->get('search_redirect', ''));

        return $this->redirectToRoute('app_my_files', $searchRedirect !== '' ? ['search' => $searchRedirect] : [], Response::HTTP_SEE_OTHER);
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
            return $this->redirectToRoute('app_my_files', [], Response::HTTP_SEE_OTHER);
        }

        $entityManager->remove($userFile);
        $entityManager->flush();

        $this->addFlash('success', 'Файл удалён.');
        return $this->redirectToRoute('app_my_files', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/my_files_upload', name: 'app_my_files_upload', methods: ['GET', 'POST'])]
    public function myFilesUpload(
        Request $request,
        EntityManagerInterface $entityManager,
        UserFolderFileRepository $userFolderFileRepository,
    ): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $folders = $userFolderFileRepository->findBy(['user' => $user], ['name' => 'ASC']);
        $selectedFolderId = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('my_files_upload', $request->request->get('_token', ''))) {
                $this->addFlash('error', 'Неверный токен. Попробуйте снова.');
                return $this->render('user/my_files_upload.html.twig', [
                    'active_tab' => 'my_files',
                    'folders' => $folders,
                    'selected_folder_id' => $selectedFolderId,
                ]);
            }

            $selectedFolderId = (int) $request->request->get('folder_id', 0);
            $selectedFolderId = $selectedFolderId > 0 ? $selectedFolderId : null;
            $folder = null;
            if ($selectedFolderId !== null) {
                $folder = $userFolderFileRepository->find($selectedFolderId);
                if (!$folder instanceof UserFolderFile || $folder->getUser()?->getId() !== $user->getId()) {
                    $this->addFlash('error', 'Папка не найдена.');
                    return $this->render('user/my_files_upload.html.twig', [
                        'active_tab' => 'my_files',
                        'folders' => $folders,
                        'selected_folder_id' => $selectedFolderId,
                    ]);
                }
            }

            $uploadedFiles = $request->files->get('files');
            if (!\is_array($uploadedFiles)) {
                $uploadedFiles = $uploadedFiles ? [$uploadedFiles] : [];
            }

            $maxFileSizeBytes = 5 * 1024 * 1024; // 5 МБ
            foreach ($uploadedFiles as $uploadedFile) {
                if (!$uploadedFile instanceof UploadedFile) {
                    continue;
                }
                if ($uploadedFile->getSize() > $maxFileSizeBytes) {
                    $this->addFlash('error', sprintf(
                        'Файл «%s» превышает допустимый размер (макс. 5 МБ). Выберите файлы заново.',
                        $uploadedFile->getClientOriginalName()
                    ));
                    return $this->render('user/my_files_upload.html.twig', [
                        'active_tab' => 'my_files',
                        'folders' => $folders,
                        'selected_folder_id' => $selectedFolderId,
                    ]);
                }
                if ($uploadedFile->getError() !== \UPLOAD_ERR_OK) {
                    $this->addFlash('error', sprintf(
                        'Ошибка загрузки файла «%s». Выберите файлы заново.',
                        $uploadedFile->getClientOriginalName()
                    ));
                    return $this->render('user/my_files_upload.html.twig', [
                        'active_tab' => 'my_files',
                        'folders' => $folders,
                        'selected_folder_id' => $selectedFolderId,
                    ]);
                }
            }

            $count = 0;
            foreach ($uploadedFiles as $uploadedFile) {
                if (!$uploadedFile instanceof UploadedFile) {
                    continue;
                }
                $userFile = new UserFile();
                $userFile->setUser($user);
                $userFile->setFolder($folder);
                $userFile->setFile($uploadedFile);
                $userFile->setOriginalName($uploadedFile->getClientOriginalName());
                $userFile->setTitle(pathinfo($uploadedFile->getClientOriginalName(), \PATHINFO_FILENAME));
                $entityManager->persist($userFile);
                $count++;
            }
            $entityManager->flush();

            $this->addFlash('success', $count === 1 ? 'Файл добавлен.' : sprintf('Добавлено файлов: %d.', $count));
            return $this->redirectToRoute('app_my_files', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('user/my_files_upload.html.twig', [
            'active_tab' => 'my_files',
            'folders' => $folders,
            'selected_folder_id' => $selectedFolderId,
        ]);
    }
}
