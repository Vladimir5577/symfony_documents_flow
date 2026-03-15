<?php

namespace App\Controller\User;

use App\Entity\User\User;
use App\Entity\User\UserFile;
use App\Enum\User\UserFileType;
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
    public function myFiles(Request $request, UserFileRepository $userFileRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $search = trim((string) $request->query->get('search', ''));
        $files = $userFileRepository->findByUser($user);

        $filesGrouped = [];
        foreach ($files as $file) {
            $type = $file->getTypeForDisplay();
            $filesGrouped[$type->value][] = $file;
        }

        $typesOrder = [];
        foreach (UserFileType::cases() as $type) {
            if (!empty($filesGrouped[$type->value])) {
                $typesOrder[] = $type;
            }
        }

        return $this->render('user/my_files.html.twig', [
            'active_tab' => 'my_files',
            'files_grouped' => $filesGrouped,
            'types_order' => $typesOrder,
            'file_types' => UserFileType::cases(),
            'search' => $search,
        ]);
    }

    #[Route('/my_files/change-type/{id}', name: 'app_my_files_change_type', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function changeType(
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

        $csrfToken = 'my_files_change_type_' . $id;
        if (!$this->isCsrfTokenValid($csrfToken, $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Неверный токен. Попробуйте снова.');
            return $this->redirectToRoute('app_my_files', [], Response::HTTP_SEE_OTHER);
        }

        $typeValue = $request->request->get('type', 'other');
        $type = UserFileType::tryFrom($typeValue) ?? UserFileType::OTHER;
        $userFile->setType($type);
        $entityManager->flush();

        $this->addFlash('success', 'Тип файла изменён.');
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
    public function myFilesUpload(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $fileTypes = UserFileType::cases();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('my_files_upload', $request->request->get('_token', ''))) {
                $this->addFlash('error', 'Неверный токен. Попробуйте снова.');
                return $this->render('user/my_files_upload.html.twig', [
                    'active_tab' => 'my_files',
                    'file_types' => $fileTypes,
                ]);
            }

            $typeValue = $request->request->get('type', 'other');
            $type = UserFileType::tryFrom($typeValue) ?? UserFileType::OTHER;

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
                        'file_types' => $fileTypes,
                    ]);
                }
                if ($uploadedFile->getError() !== \UPLOAD_ERR_OK) {
                    $this->addFlash('error', sprintf(
                        'Ошибка загрузки файла «%s». Выберите файлы заново.',
                        $uploadedFile->getClientOriginalName()
                    ));
                    return $this->render('user/my_files_upload.html.twig', [
                        'active_tab' => 'my_files',
                        'file_types' => $fileTypes,
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
                $userFile->setType($type);
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
            'file_types' => $fileTypes,
        ]);
    }
}
