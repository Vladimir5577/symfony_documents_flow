<?php

namespace App\Controller\Post;

use App\Entity\Post\File as PostFile;
use App\Entity\Post\Post;
use App\Entity\User\User;
use App\Enum\Post\PostType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PostController extends AbstractController
{
    #[Route('/create_post', name: 'app_create_post', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function createPost(
        Request                $request,
        EntityManagerInterface $entityManager,
        ValidatorInterface     $validator,
    ): Response {
        $postTypes = PostType::cases();

        $renderForm = function (array $formData = []) use ($postTypes): Response {
            return $this->render('post/create_post.html.twig', [
                'active_tab' => 'create_post',
                'form_data' => $formData,
                'post_types' => $postTypes,
            ]);
        };

        if (!$request->isMethod('POST')) {
            return $renderForm();
        }

        $formData = $request->request->all();

        // CSRF
        if (!$this->isCsrfTokenValid('create_post', $formData['_csrf_token'] ?? '')) {
            $this->addFlash('error', 'Неверный CSRF токен.');
            return $renderForm($formData);
        }

        // Обязательные поля
        $title = trim((string)($formData['title'] ?? ''));
        if ($title === '') {
            $this->addFlash('error', 'Заголовок обязателен для заполнения.');
            return $renderForm($formData);
        }

        $typeValue = trim((string)($formData['type'] ?? ''));
        if ($typeValue === '') {
            $this->addFlash('error', 'Тип поста обязателен для заполнения.');
            return $renderForm($formData);
        }

        try {
            $postType = PostType::from($typeValue);
        } catch (\ValueError $e) {
            $this->addFlash('error', 'Неверный тип поста.');
            return $renderForm($formData);
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Пользователь не авторизован.');
            return $this->redirectToRoute('app_login');
        }

        // Создаём пост
        $post = new Post();
        $post->setTitle($title);
        $post->setType($postType);
        $post->setContent(trim((string)($formData['content'] ?? '')) ?: null);
        $post->setAuthor($currentUser);
        $post->setIsActive(isset($formData['is_active']));
        $post->setIsRequiredAcknowledgment(isset($formData['is_required_acknowledgment']));

        // Валидация сущности
        $errors = $validator->validate($post);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error->getMessage());
            }
            return $renderForm($formData);
        }

        // Валидация обложки
        $coverImage = $request->files->get('cover_image');
        $maxFileSizeBytes = 5 * 1024 * 1024;

        if ($coverImage instanceof UploadedFile) {
            $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
            if (!in_array($coverImage->getMimeType(), $allowedMimeTypes, true)) {
                $this->addFlash('error', 'Обложка должна быть изображением (JPG, PNG или WebP).');
                return $renderForm($formData);
            }
            if ($coverImage->getSize() > $maxFileSizeBytes) {
                $this->addFlash('error', 'Обложка превышает допустимый размер (макс. 5 МБ).');
                return $renderForm($formData);
            }
            if ($coverImage->getError() !== \UPLOAD_ERR_OK) {
                $this->addFlash('error', 'Ошибка загрузки обложки.');
                return $renderForm($formData);
            }
        }

        // Валидация файлов
        $uploadedFiles = $request->files->get('files');
        if (!\is_array($uploadedFiles)) {
            $uploadedFiles = $uploadedFiles ? [$uploadedFiles] : [];
        }

        foreach ($uploadedFiles as $uploadedFile) {
            if (!$uploadedFile instanceof UploadedFile) {
                continue;
            }
            if ($uploadedFile->getSize() > $maxFileSizeBytes) {
                $this->addFlash('error', sprintf(
                    'Файл «%s» превышает допустимый размер (макс. 5 МБ).',
                    $uploadedFile->getClientOriginalName()
                ));
                return $renderForm($formData);
            }
            if ($uploadedFile->getError() !== \UPLOAD_ERR_OK) {
                $this->addFlash('error', sprintf(
                    'Ошибка загрузки файла «%s».',
                    $uploadedFile->getClientOriginalName()
                ));
                return $renderForm($formData);
            }
        }

        // Сохраняем
        $entityManager->persist($post);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        try {
            // Flush чтобы получить ID поста (нужен для Vich directory namer)
            $entityManager->flush();

            // Обложка
            if ($coverImage instanceof UploadedFile) {
                $post->setCoverImageFile($coverImage);
            }

            // Файлы
            foreach ($uploadedFiles as $uploadedFile) {
                if (!$uploadedFile instanceof UploadedFile) {
                    continue;
                }
                $fileEntity = new PostFile();
                $fileEntity->setPost($post);
                $fileEntity->setFile($uploadedFile);
                $fileEntity->setTitle(pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME));
                $post->addFile($fileEntity);
                $entityManager->persist($fileEntity);
            }

            $entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        $this->addFlash('success', 'Пост успешно создан.');

        return $this->redirectToRoute('app_dash_board');
    }
}
