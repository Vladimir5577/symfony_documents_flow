<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Post;

use App\Controller\SpaApi\SpaApiError;
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
use App\Service\SpaApi\Post\PostCreateService;
use App\Service\SpaApi\Post\PostResponseFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spa/api/posts')]
final class PostController extends AbstractController
{
    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly PostUserCommentRepository $commentRepository,
        private readonly PostUserStatusRepository $statusRepository,
        private readonly FileRepository $fileRepository,
        private readonly PostResponseFormatter $formatter,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%private_upload_dir_posts%')]
        private readonly string $uploadDirPosts,
    ) {
    }

    #[Route('', name: 'spa_api_posts_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $typeParam = trim((string) $request->query->get('type', ''));
        $type = $typeParam !== '' ? PostType::tryFrom($typeParam) : null;

        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, min(100, $request->query->getInt('page_size', 10)));

        // is_active=0 — неопубликованные (MANAGER: свои; ADMIN: все). По умолчанию только активные.
        $isActiveParam = $request->query->get('is_active');
        $isActive = !\in_array((string) $isActiveParam, ['0', 'false'], true);
        $author = null;
        if (!$isActive) {
            if (!$this->isGranted('ROLE_MANAGER')) {
                return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
            }
            if (!$this->isGranted('ROLE_ADMIN')) {
                $author = $user;
            }
        }

        $posts = $this->postRepository->findActivePaginated($type, $page, $limit, $isActive, $author);
        $total = $this->postRepository->countActive($type, $isActive, $author);

        $postIds = array_map(static fn (Post $p): ?int => $p->getId(), $posts);
        $commentCounts = $this->commentRepository->countGroupedByPosts($postIds);
        $statuses = $this->statusRepository->findStatusesByPostsAndUser($postIds, $user);

        return $this->json([
            'items' => array_map(
                fn (Post $post): array => $this->formatter->formatPostListItem(
                    $post,
                    $commentCounts[$post->getId()] ?? 0,
                    $statuses[$post->getId()] ?? null,
                ),
                $posts,
            ),
            'pagination' => $this->formatter->formatPagination($page, $limit, $total),
            'filters' => [
                'typeChoices' => $this->formatter->formatTypeChoices(),
            ],
        ]);
    }

    #[Route('/{id}', name: 'spa_api_posts_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function view(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $post = $this->postRepository->find($id);
        if ($post === null || $post->getDeletedAt() !== null) {
            return $this->json(['error' => SpaApiError::POST_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $status = $this->statusRepository->findOneBy(['post' => $post, 'user' => $user])?->getStatus();
        $commentCount = $this->commentRepository->count(['post' => $post]);

        return $this->json($this->formatter->formatPostDetail($post, $status, $commentCount));
    }

    #[Route('', name: 'spa_api_posts_create', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function create(
        Request $request,
        #[CurrentUser] User $user,
        PostCreateService $createService,
    ): JsonResponse {
        try {
            $post = $createService->createFromRequest($request, $user);
        } catch (HttpException $e) {
            $message = $e->getMessage();

            return $this->json(
                ['error' => $message !== '' ? $message : SpaApiError::POST_INVALID_TYPE],
                $e->getStatusCode(),
            );
        }

        return $this->json($this->formatter->formatPostDetail($post), Response::HTTP_CREATED);
    }

    #[Route('/{id}/active', name: 'spa_api_posts_set_active', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    #[IsGranted('ROLE_MANAGER')]
    public function setActive(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $post = $this->postRepository->find($id);
        if ($post === null) {
            return $this->json(['error' => SpaApiError::POST_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isGranted('ROLE_ADMIN') && $post->getAuthor()?->getId() !== $user->getId()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload) || !\array_key_exists('is_active', $payload)) {
            return $this->json(['error' => SpaApiError::UPDATE_FIELDS_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $post->setIsActive((bool) $payload['is_active']);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'isActive' => $post->isActive(),
        ]);
    }

    #[Route('/{id}', name: 'spa_api_posts_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[IsGranted('ROLE_MANAGER')]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $post = $this->postRepository->find($id);
        if ($post === null) {
            return $this->json(['error' => SpaApiError::POST_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isGranted('ROLE_ADMIN') && $post->getAuthor()?->getId() !== $user->getId()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        // SoftDeleteable: remove → UPDATE deleted_at (не DELETE из БД)
        $this->entityManager->remove($post);
        $this->entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/comments', name: 'spa_api_posts_comments_list', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function listComments(int $id, Request $request): JsonResponse
    {
        $post = $this->postRepository->find($id);
        if ($post === null || $post->getDeletedAt() !== null) {
            return $this->json(['error' => SpaApiError::POST_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $offset = max(0, $request->query->getInt('offset', 0));
        $limit = max(1, min(100, $request->query->getInt('limit', 5)));

        $comments = $this->commentRepository->findByPostPaginated($post, $offset, $limit);
        $total = $this->commentRepository->count(['post' => $post]);

        return $this->json([
            'items' => array_map(
                fn (PostUserComment $comment): array => $this->formatter->formatComment($comment),
                $comments,
            ),
            'hasMore' => ($offset + $limit) < $total,
            'nextOffset' => $offset + $limit,
            'total' => $total,
        ]);
    }

    #[Route('/{id}/comments', name: 'spa_api_posts_comments_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function createComment(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $post = $this->postRepository->find($id);
        if ($post === null || $post->getDeletedAt() !== null) {
            return $this->json(['error' => SpaApiError::POST_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $content = trim((string) ($payload['content'] ?? ''));
        if ($content === '') {
            return $this->json(['error' => SpaApiError::POST_COMMENT_EMPTY], Response::HTTP_BAD_REQUEST);
        }

        $comment = new PostUserComment();
        $comment->setPost($post);
        $comment->setUser($user);
        $comment->setContent($content);

        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        return $this->json($this->formatter->formatComment($comment), Response::HTTP_CREATED);
    }

    #[Route('/{id}/acknowledge', name: 'spa_api_posts_acknowledge', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function acknowledge(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $post = $this->postRepository->find($id);
        if ($post === null || $post->getDeletedAt() !== null) {
            return $this->json(['error' => SpaApiError::POST_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $status = $this->statusRepository->findOneBy(['post' => $post, 'user' => $user]);
        if ($status === null) {
            $status = new PostUserStatus();
            $status->setPost($post);
            $status->setUser($user);
            $this->entityManager->persist($status);
        }

        $status->setStatus(PostUserStatusType::ACKNOWLEDGED);
        $status->setViewedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'userStatus' => [
                'value' => PostUserStatusType::ACKNOWLEDGED->value,
                'label' => PostUserStatusType::ACKNOWLEDGED->label(),
            ],
        ]);
    }

    #[Route('/files/{fileId}/download', name: 'spa_api_posts_file_download', requirements: ['fileId' => '\d+'], methods: ['GET'])]
    public function downloadFile(int $fileId, Request $request): Response
    {
        $fileEntity = $this->fileRepository->find($fileId);
        if (!$fileEntity instanceof PostFile) {
            return $this->json(['error' => SpaApiError::POST_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $filePath = $fileEntity->getFilePath();
        if ($filePath === null) {
            return $this->json(['error' => SpaApiError::POST_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $postId = $fileEntity->getPost()?->getId();
        $absolutePath = str_contains($filePath, '/')
            ? $this->uploadDirPosts . '/' . $filePath
            : $this->uploadDirPosts . '/' . $postId . '/' . $filePath;

        if (!is_file($absolutePath)) {
            return $this->json(['error' => SpaApiError::POST_FILE_NOT_FOUND_ON_DISK], Response::HTTP_NOT_FOUND);
        }

        $filename = $fileEntity->getTitle()
            ? $fileEntity->getTitle() . '.' . pathinfo($filePath, PATHINFO_EXTENSION)
            : $filePath;

        $response = new BinaryFileResponse($absolutePath);
        $disposition = $request->query->getBoolean('inline', false)
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        $response->setContentDisposition($disposition, $filename);

        return $response;
    }
}
