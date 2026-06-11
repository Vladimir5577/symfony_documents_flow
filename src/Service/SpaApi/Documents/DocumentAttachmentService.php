<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\File;
use App\Repository\Document\FileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException as HttpBadRequestException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class DocumentAttachmentService
{
    public const MAX_ATTACHMENTS_PER_DOCUMENT = 16;

    private const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileRepository $fileRepository,
        #[Autowire('%private_upload_dir_documents_originals%')]
        private readonly string $documentsOriginalsDir,
    ) {
    }

    public function upload(Document $document, UploadedFile $uploadedFile): File
    {
        if ($this->fileRepository->countByDocument($document) >= self::MAX_ATTACHMENTS_PER_DOCUMENT) {
            throw new HttpBadRequestException(SpaApiError::ATTACHMENT_LIMIT_REACHED);
        }

        if (!$uploadedFile->isValid()) {
            throw new BadRequestHttpException(
                $uploadedFile->getErrorMessage() ?: 'Не удалось загрузить файл.',
            );
        }

        $size = $uploadedFile->getSize();
        if ($size !== false && $size > self::MAX_SIZE_BYTES) {
            throw new BadRequestHttpException('Файл слишком большой (максимум 10 МБ).');
        }

        $mimeType = $uploadedFile->getMimeType();
        if ($mimeType !== null && !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new BadRequestHttpException('Допустимые форматы: PDF, DOC, DOCX, JPEG, PNG, WEBP.');
        }

        $clientName = $uploadedFile->getClientOriginalName();
        $fileEntity = new File();
        $fileEntity->setDocument($document);
        $fileEntity->setTitle(pathinfo($clientName, PATHINFO_FILENAME));
        $fileEntity->setFile($uploadedFile);
        $document->addFile($fileEntity);

        $document->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($fileEntity);
        $this->entityManager->flush();

        return $fileEntity;
    }

    public function delete(File $file): void
    {
        $document = $file->getDocument();
        if ($document !== null) {
            $document->setUpdatedAt(new \DateTimeImmutable());
        }

        $this->entityManager->remove($file);
        $this->entityManager->flush();
    }

    public function resolveAbsolutePath(File $file): ?string
    {
        $filePath = $file->getFilePath();
        if ($filePath === null || $filePath === '') {
            return null;
        }

        if (str_contains($filePath, '/')) {
            $absolutePath = $this->documentsOriginalsDir . '/' . $filePath;

            return is_file($absolutePath) ? $absolutePath : null;
        }

        $documentId = $file->getDocument()?->getId();
        if ($documentId === null) {
            return null;
        }

        $absolutePath = $this->documentsOriginalsDir . '/' . $documentId . '/' . $filePath;

        return is_file($absolutePath) ? $absolutePath : null;
    }

    /**
     * @return array{
     *     id: int|null,
     *     filename: string,
     *     contentType: string|null,
     *     size: int|null,
     *     createdAt: string|null
     * }
     */
    public function presentAttachment(File $file): array
    {
        $filePath = $file->getFilePath();
        $extension = $filePath !== null && $filePath !== '' ? pathinfo($filePath, PATHINFO_EXTENSION) : '';
        $title = $file->getTitle();
        $filename = $title !== null && $title !== ''
            ? ($extension !== '' ? $title . '.' . $extension : $title)
            : ($filePath ?? 'file');

        $absolutePath = $this->resolveAbsolutePath($file);
        $size = null;
        $contentType = null;

        if ($absolutePath !== null) {
            $detectedSize = filesize($absolutePath);
            $size = $detectedSize !== false ? $detectedSize : null;
            $detectedMime = mime_content_type($absolutePath);
            $contentType = $detectedMime !== false ? $detectedMime : null;
        }

        return [
            'id' => $file->getId(),
            'filename' => $filename,
            'contentType' => $contentType,
            'size' => $size,
            'createdAt' => null,
        ];
    }

    /**
     * @param iterable<File> $files
     *
     * @return list<array{
     *     id: int|null,
     *     filename: string,
     *     contentType: string|null,
     *     size: int|null,
     *     createdAt: string|null
     * }>
     */
    public function presentAttachments(iterable $files): array
    {
        $rows = [];
        foreach ($files as $file) {
            $rows[] = $this->presentAttachment($file);
        }

        return $rows;
    }
}
