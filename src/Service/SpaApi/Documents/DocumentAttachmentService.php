<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\File;
use App\Repository\Document\FileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class DocumentAttachmentService
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    public function __construct(
        private readonly FileRepository $fileRepository,
        private readonly DocumentApiPresenter $presenter,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%private_upload_dir_documents_originals%')]
        private readonly string $uploadDir,
    ) {
    }

    public function upload(Document $document, UploadedFile $uploaded): File
    {
        if ($uploaded->getSize() > self::MAX_FILE_SIZE) {
            throw new BadRequestHttpException(SpaApiError::POST_FILE_TOO_LARGE);
        }

        $clientName = $uploaded->getClientOriginalName();
        $fileEntity = new File();
        $fileEntity->setDocument($document);
        $fileEntity->setFile($uploaded);
        $fileEntity->setTitle(pathinfo($clientName, PATHINFO_FILENAME));
        $document->addFile($fileEntity);
        $document->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($fileEntity);
        $this->entityManager->flush();

        return $fileEntity;
    }

    public function delete(Document $document, File $fileEntity): void
    {
        if ($fileEntity->getDocument()?->getId() !== $document->getId()) {
            throw new BadRequestHttpException(SpaApiError::ATTACHMENT_NOT_FOUND);
        }

        $document->removeFile($fileEntity);
        $document->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->remove($fileEntity);
        $this->entityManager->flush();
    }

    public function findForDocument(int $documentId, int $fileId): ?File
    {
        $fileEntity = $this->fileRepository->find($fileId);
        if ($fileEntity === null || $fileEntity->getDocument()?->getId() !== $documentId) {
            return null;
        }

        return $fileEntity;
    }

    public function resolveFilePath(Document $document, File $fileEntity): ?string
    {
        $filePath = $fileEntity->getFilePath();
        if ($filePath === null || $filePath === '') {
            return null;
        }

        $documentId = $document->getId();
        $absolutePath = str_contains($filePath, '/')
            ? $this->uploadDir . '/' . $filePath
            : $this->uploadDir . '/' . $documentId . '/' . $filePath;

        return is_file($absolutePath) ? $absolutePath : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentFile(Document $document, File $fileEntity): array
    {
        $absolutePath = $this->resolveFilePath($document, $fileEntity);
        $size = $absolutePath !== null ? (filesize($absolutePath) ?: null) : null;
        $contentType = $absolutePath !== null ? (mime_content_type($absolutePath) ?: null) : null;

        return $this->presenter->presentDocumentFile($fileEntity, $size, $contentType);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function presentFilesForDocument(Document $document): array
    {
        $rows = [];
        foreach ($document->getFiles() as $file) {
            if ($file instanceof File) {
                $rows[] = $this->presentFile($document, $file);
            }
        }

        return $rows;
    }
}
