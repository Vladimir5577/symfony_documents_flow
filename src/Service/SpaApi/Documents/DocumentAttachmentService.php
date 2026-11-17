<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\File;
use App\Repository\Document\FileRepository;
use App\Service\Document\DocumentFileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class DocumentAttachmentService
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    public function __construct(
        private readonly FileRepository $fileRepository,
        private readonly DocumentApiPresenter $presenter,
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentFileStorage $storage,
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
        $this->storage->storeAttachment($fileEntity, $uploaded);
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

    /** То же имя, что в карточке: title и расширение из пути. */
    public function displayName(File $file): string
    {
        return (string) $this->presenter->presentDocumentFile($file)['filename'];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentFile(File $fileEntity): array
    {
        return $this->presenter->presentDocumentFile($fileEntity, $fileEntity->getSizeBytes(), $fileEntity->getContentType());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function presentFilesForDocument(Document $document): array
    {
        $rows = [];
        foreach ($document->getFiles() as $file) {
            if ($file instanceof File) {
                $rows[] = $this->presentFile($file);
            }
        }

        return $rows;
    }
}
