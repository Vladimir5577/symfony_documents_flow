<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Post;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Post\File as PostFile;
use App\Entity\Post\Post;
use App\Entity\User\User;
use App\Enum\Post\PostType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Vich\UploaderBundle\Handler\UploadHandler;

final class PostCreateService
{
    private const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024;
    private const ALLOWED_COVER_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const COVER_FIELD = 'coverImageFile';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly UploadHandler $uploadHandler,
    ) {
    }

    /**
     * Создаёт пост из multipart-запроса (обложка + файлы).
     *
     * @throws HttpException с кодом SpaApiError при ошибке валидации
     */
    public function createFromRequest(Request $request, User $author): Post
    {
        $title = trim((string) $request->request->get('title', ''));
        if ($title === '') {
            throw new HttpException(Response::HTTP_BAD_REQUEST, SpaApiError::POST_TITLE_REQUIRED);
        }

        $typeValue = trim((string) $request->request->get('type', ''));
        if ($typeValue === '') {
            throw new HttpException(Response::HTTP_BAD_REQUEST, SpaApiError::POST_TYPE_REQUIRED);
        }

        $postType = PostType::tryFrom($typeValue);
        if ($postType === null) {
            throw new HttpException(Response::HTTP_BAD_REQUEST, SpaApiError::POST_INVALID_TYPE);
        }

        $post = new Post();
        $post->setTitle($title);
        $post->setType($postType);
        $post->setContent(trim((string) $request->request->get('content', '')) ?: null);
        $post->setAuthor($author);
        $post->setIsActive($request->request->getBoolean('is_active'));
        $post->setIsRequiredAcknowledgment($request->request->getBoolean('is_required_acknowledgment'));

        $errors = $this->validator->validate($post);
        if (count($errors) > 0) {
            throw new HttpException(Response::HTTP_BAD_REQUEST, (string) $errors->get(0)->getMessage());
        }

        $coverImage = $request->files->get('cover_image');
        if ($coverImage instanceof UploadedFile) {
            $this->validateCover($coverImage);
        }

        $uploadedFiles = $request->files->get('files');
        if (!\is_array($uploadedFiles)) {
            $uploadedFiles = $uploadedFiles ? [$uploadedFiles] : [];
        }
        $this->validateFiles($uploadedFiles);

        $this->entityManager->persist($post);

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            // Flush чтобы получить ID поста (нужен для Vich directory namer)
            $this->entityManager->flush();

            if ($coverImage instanceof UploadedFile) {
                $post->setCoverImageFile($coverImage);
                // Vich слушает preUpdate; после первого flush сущность уже managed и без
                // изменения mapped-полей Doctrine не вызывает preUpdate — загружаем явно.
                $this->uploadHandler->upload($post, self::COVER_FIELD);
            }

            foreach ($uploadedFiles as $uploadedFile) {
                if (!$uploadedFile instanceof UploadedFile) {
                    continue;
                }
                $fileEntity = new PostFile();
                $fileEntity->setPost($post);
                $fileEntity->setFile($uploadedFile);
                $fileEntity->setTitle(pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME));
                $post->addFile($fileEntity);
                $this->entityManager->persist($fileEntity);
            }

            $this->entityManager->flush();
            $connection->commit();
            $this->entityManager->refresh($post);
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $post;
    }

    private function validateCover(UploadedFile $coverImage): void
    {
        if (!in_array($coverImage->getMimeType(), self::ALLOWED_COVER_MIME_TYPES, true)) {
            throw new HttpException(Response::HTTP_BAD_REQUEST, SpaApiError::POST_COVER_INVALID_IMAGE);
        }
        if ($coverImage->getSize() > self::MAX_FILE_SIZE_BYTES) {
            throw new HttpException(Response::HTTP_BAD_REQUEST, SpaApiError::POST_COVER_TOO_LARGE);
        }
        if ($coverImage->getError() !== \UPLOAD_ERR_OK) {
            throw new HttpException(Response::HTTP_BAD_REQUEST, SpaApiError::POST_FILE_UPLOAD_ERROR);
        }
    }

    /**
     * @param array<mixed> $uploadedFiles
     */
    private function validateFiles(array $uploadedFiles): void
    {
        foreach ($uploadedFiles as $uploadedFile) {
            if (!$uploadedFile instanceof UploadedFile) {
                continue;
            }
            if ($uploadedFile->getSize() > self::MAX_FILE_SIZE_BYTES) {
                throw new HttpException(Response::HTTP_BAD_REQUEST, SpaApiError::POST_FILE_TOO_LARGE);
            }
            if ($uploadedFile->getError() !== \UPLOAD_ERR_OK) {
                throw new HttpException(Response::HTTP_BAD_REQUEST, SpaApiError::POST_FILE_UPLOAD_ERROR);
            }
        }
    }
}
