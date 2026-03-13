<?php

namespace App\Controller\Post;

use App\Entity\Post\File as PostFile;
use App\Entity\Post\Post;
use App\Entity\Post\PostUserComment;
use App\Entity\Post\PostUserStatus;
use App\Entity\User\User;
use App\Enum\Post\PostType;
use App\Enum\Post\PostUserStatusType;
use App\Repository\Post\FileRepository;
use App\Repository\Post\PostRepository;
use App\Repository\Post\PostUserCommentRepository;
use App\Repository\Post\PostUserStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PostController extends AbstractController
{
    #[Route('/posts', name: 'app_all_posts', methods: ['GET'])]
    public function allPosts(
        Request                    $request,
        PostRepository             $postRepository,
        PostUserCommentRepository  $commentRepository,
        PostUserStatusRepository   $statusRepository,
    ): Response {
        $typeFilter = $request->query->get('type');
        $postType = null;
        if ($typeFilter) {
            $postType = PostType::tryFrom($typeFilter);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 10;

        $posts = $postRepository->findActivePaginated($postType, $page, $limit);
        $total = $postRepository->countActive($postType);
        $hasMore = ($page * $limit) < $total;

        $postIds = array_map(fn(Post $p) => $p->getId(), $posts);

        $comments = $commentRepository->findLatestGroupedByPosts($postIds);
        $commentCounts = $commentRepository->countGroupedByPosts($postIds);

        $user = $this->getUser();
        $statuses = [];
        if ($user instanceof User) {
            $statuses = $statusRepository->findStatusesByPostsAndUser($postIds, $user);
        }

        return $this->render('post/all_posts.html.twig', [
            'active_tab' => 'all_posts',
            'posts' => $posts,
            'comments' => $comments,
            'comment_counts' => $commentCounts,
            'statuses' => $statuses,
            'has_more' => $hasMore,
            'page' => $page,
            'post_types' => PostType::cases(),
            'current_type' => $typeFilter,
        ]);
    }

    #[Route('/posts/load-more', name: 'app_all_posts_load_more', methods: ['GET'])]
    public function loadMorePosts(
        Request                    $request,
        PostRepository             $postRepository,
        PostUserCommentRepository  $commentRepository,
        PostUserStatusRepository   $statusRepository,
    ): Response {
        $typeFilter = $request->query->get('type');
        $postType = null;
        if ($typeFilter) {
            $postType = PostType::tryFrom($typeFilter);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 10;

        $posts = $postRepository->findActivePaginated($postType, $page, $limit);
        $total = $postRepository->countActive($postType);
        $hasMore = ($page * $limit) < $total;

        $postIds = array_map(fn(Post $p) => $p->getId(), $posts);

        $comments = $commentRepository->findLatestGroupedByPosts($postIds);
        $commentCounts = $commentRepository->countGroupedByPosts($postIds);

        $user = $this->getUser();
        $statuses = [];
        if ($user instanceof User) {
            $statuses = $statusRepository->findStatusesByPostsAndUser($postIds, $user);
        }

        return $this->render('post/partials/_post_cards.html.twig', [
            'posts' => $posts,
            'comments' => $comments,
            'comment_counts' => $commentCounts,
            'statuses' => $statuses,
            'has_more' => $hasMore,
            'page' => $page,
        ]);
    }

    #[Route('/posts/{id}/comment', name: 'app_post_add_comment', methods: ['POST'])]
    public function addComment(
        Post                       $post,
        Request                    $request,
        EntityManagerInterface     $entityManager,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $content = trim((string) $request->request->get('content', ''));
        if ($content === '') {
            return new JsonResponse(['error' => 'Комментарий не может быть пустым'], Response::HTTP_BAD_REQUEST);
        }

        $comment = new PostUserComment();
        $comment->setPost($post);
        $comment->setUser($user);
        $comment->setContent($content);

        $entityManager->persist($comment);
        $entityManager->flush();

        $html = $this->renderView('post/partials/_comment.html.twig', [
            'comment' => $comment,
        ]);

        return new JsonResponse([
            'success' => true,
            'html' => $html,
        ]);
    }

    #[Route('/posts/{id}/comments', name: 'app_post_load_comments', methods: ['GET'])]
    public function loadComments(
        Post                       $post,
        Request                    $request,
        PostUserCommentRepository  $commentRepository,
    ): Response {
        $offset = max(0, $request->query->getInt('offset', 0));
        $limit = 5;

        $comments = $commentRepository->findByPostPaginated($post, $offset, $limit);

        $totalCount = $commentRepository->count(['post' => $post]);
        $hasMore = ($offset + $limit) < $totalCount;

        return $this->render('post/partials/_comments_list.html.twig', [
            'comments' => $comments,
            'has_more' => $hasMore,
            'next_offset' => $offset + $limit,
            'post' => $post,
        ]);
    }

    #[Route('/posts/{id}/acknowledge', name: 'app_post_acknowledge', methods: ['POST'])]
    public function acknowledgePost(
        Post                       $post,
        EntityManagerInterface     $entityManager,
        PostUserStatusRepository   $statusRepository,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $existing = $statusRepository->findOneBy(['post' => $post, 'user' => $user]);

        if ($existing) {
            $existing->setStatus(PostUserStatusType::ACKNOWLEDGED);
            $existing->setViewedAt(new \DateTimeImmutable());
        } else {
            $status = new PostUserStatus();
            $status->setPost($post);
            $status->setUser($user);
            $status->setStatus(PostUserStatusType::ACKNOWLEDGED);
            $status->setViewedAt(new \DateTimeImmutable());
            $entityManager->persist($status);
        }

        $entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/posts/file/{id}/download', name: 'app_post_file_download', methods: ['GET'])]
    public function downloadFile(
        int            $id,
        FileRepository $fileRepository,
    ): Response {
        $fileEntity = $fileRepository->find($id);
        if (!$fileEntity instanceof PostFile) {
            throw $this->createNotFoundException('Файл не найден.');
        }

        $filePath = $fileEntity->getFilePath();
        if (!$filePath) {
            throw $this->createNotFoundException('Файл не прикреплён.');
        }

        $postId = $fileEntity->getPost()?->getId();
        $uploadDir = $this->getParameter('private_upload_dir_posts');
        $absolutePath = str_contains($filePath, '/')
            ? $uploadDir . '/' . $filePath
            : $uploadDir . '/' . $postId . '/' . $filePath;

        if (!is_file($absolutePath)) {
            throw $this->createNotFoundException('Файл не найден на диске.');
        }

        $filename = $fileEntity->getTitle()
            ? $fileEntity->getTitle() . '.' . pathinfo($filePath, PATHINFO_EXTENSION)
            : $filePath;

        $response = new StreamedResponse(static function () use ($absolutePath) {
            $handle = fopen($absolutePath, 'rb');
            if ($handle === false) {
                return;
            }
            while (!feof($handle)) {
                echo fread($handle, 8192);
                flush();
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', mime_content_type($absolutePath) ?: 'application/octet-stream');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . addslashes($filename) . '"');

        return $response;
    }

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
