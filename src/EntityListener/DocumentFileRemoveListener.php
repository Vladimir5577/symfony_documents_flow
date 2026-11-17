<?php

declare(strict_types=1);

namespace App\EntityListener;

use App\Entity\Document\DocumentCommentFile;
use App\Entity\Document\File;
use App\Service\Document\DocumentFileStorage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;

/**
 * Удаляет объект из MinIO вместе со строкой — в том числе каскадом от
 * документа и комментария. Раньше это делал Vich (delete_on_remove).
 */
#[AsEntityListener(event: 'postRemove', entity: File::class)]
#[AsEntityListener(event: 'postRemove', entity: DocumentCommentFile::class)]
final class DocumentFileRemoveListener
{
    public function __construct(private readonly DocumentFileStorage $storage)
    {
    }

    public function postRemove(File|DocumentCommentFile $file): void
    {
        $this->storage->delete($file instanceof File
            ? DocumentFileStorage::attachmentKey($file)
            : DocumentFileStorage::commentFileKey($file));
    }
}
