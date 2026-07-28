<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Document;
use App\Entity\Inventory\DocumentFile;
use App\Entity\User\User;
use App\Enum\Inventory\DocumentStatus;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Сканы УПД/актов: Vich (маппинг inventory_document_files, приватный диск).
 * Отличия от эталонного DocumentAttachmentService (усиление по итогам дебатов):
 * лимит 20MB, whitelist MIME, ЗАПРЕТ изменений после проведения документа.
 */
final class InventoryAttachmentService
{
    private const MAX_FILE_SIZE = 20 * 1024 * 1024;

    /** @var list<string> */
    private const ALLOWED_MIME = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InventoryHistoryService $history,
        #[Autowire('%private_upload_dir_inventory%')]
        private readonly string $uploadDir,
    ) {
    }

    public function upload(Document $document, UploadedFile $uploaded, User $actor): DocumentFile
    {
        if (!$uploaded->isValid()) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_FILE_REQUIRED);
        }
        if ($uploaded->getSize() > self::MAX_FILE_SIZE) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_FILE_TOO_LARGE);
        }
        $mime = (string) $uploaded->getMimeType();
        if (!\in_array($mime, self::ALLOWED_MIME, true)) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_FILE_INVALID_TYPE);
        }

        $file = new DocumentFile();

        // Заморозка TOCTOU (ревью 2026-07-28): блокировка документа + перепроверка DRAFT
        // в одной транзакции с записью — скан не прикрепится к параллельно проведённому документу.
        $this->em->wrapInTransaction(function () use ($document, $uploaded, $actor, $mime, $file): void {
            $this->lockAndAssertMutable($document);

            $file->setDocument($document);
            $file->setFilename($uploaded->getClientOriginalName());
            $file->setContentType($mime);
            $file->setSizeBytes((int) $uploaded->getSize());
            $file->setUploadedBy($actor);
            $file->setFile($uploaded); // Vich перенесёт файл и заполнит storageKey

            $this->em->persist($file);
            $this->history->log('document', (int) $document->getId(), 'attachment_add', $actor, [
                'filename' => $uploaded->getClientOriginalName(),
            ]);
            $this->em->flush();
        });

        return $file;
    }

    public function delete(Document $document, DocumentFile $file, User $actor): void
    {
        // Скан обязан принадлежать переданному документу (защита от подмены пары)
        if ($file->getDocument()?->getId() !== $document->getId()) {
            throw new BadRequestHttpException(SpaApiError::INVENTORY_INVALID_PAYLOAD);
        }

        $this->em->wrapInTransaction(function () use ($document, $file, $actor): void {
            $this->lockAndAssertMutable($document);

            $this->history->log('document', (int) $document->getId(), 'attachment_delete', $actor, [
                'filename' => $file->getFilename(),
            ]);
            $this->em->remove($file); // Vich delete_on_remove удалит физический файл
            $this->em->flush();
        });
    }

    public function resolveFilePath(DocumentFile $file): string
    {
        $documentId = $file->getDocument()?->getId();
        $storageKey = $file->getStorageKey();
        if ($documentId === null || $storageKey === null) {
            throw new NotFoundHttpException(SpaApiError::INVENTORY_NOT_FOUND);
        }

        $path = rtrim($this->uploadDir, '/\\') . \DIRECTORY_SEPARATOR . $documentId . \DIRECTORY_SEPARATOR . $storageKey;
        if (!is_file($path)) {
            throw new NotFoundHttpException(SpaApiError::INVENTORY_NOT_FOUND);
        }

        return $path;
    }

    private function lockAndAssertMutable(Document $document): void
    {
        $this->em->lock($document, LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($document);

        if ($document->getStatus() !== DocumentStatus::DRAFT) {
            throw new ConflictHttpException(SpaApiError::INVENTORY_DOCUMENT_FROZEN);
        }
    }
}
