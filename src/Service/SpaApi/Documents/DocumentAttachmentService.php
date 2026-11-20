<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\File;
use App\Repository\Document\FileRepository;
use App\Service\Document\Convertor\DocxToPdfConvertorService;
use App\Service\Document\DocumentFileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
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
        private readonly DocxToPdfConvertorService $docxToPdf,
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

    /**
     * PDF-копия docx отдельным вложением: автор смотрит, не поехала ли вёрстка,
     * и подписывают уже её. Сам docx остаётся как был.
     */
    public function convertToPdf(Document $document, File $docx): File
    {
        if (strtolower(pathinfo((string) $docx->getFilePath(), PATHINFO_EXTENSION)) !== 'docx') {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_FILE_CONVERSION_FAILED);
        }

        $fs = new Filesystem();
        $dir = sys_get_temp_dir() . '/docx_to_pdf_' . bin2hex(random_bytes(8));
        $fs->mkdir($dir);

        try {
            $source = $dir . '/source.docx';
            if (!$this->storage->saveTo(DocumentFileStorage::attachmentKey($docx), $source)) {
                throw new BadRequestHttpException(SpaApiError::FILE_NOT_FOUND_ON_DISK);
            }

            $pdf = $this->docxToPdf->convert($source, $dir);
            $name = pathinfo($this->displayName($docx), PATHINFO_FILENAME) . '.pdf';

            return $this->upload($document, new UploadedFile($pdf, $name, 'application/pdf', null, true));
        } finally {
            $fs->remove($dir);
        }
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
