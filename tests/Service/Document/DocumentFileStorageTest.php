<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\Document\Document;
use App\Entity\Document\DocumentComment;
use App\Entity\Document\DocumentCommentFile;
use App\Entity\Document\File;
use App\Service\Document\DocumentFileStorage;
use PHPUnit\Framework\TestCase;

/** Ключ в MinIO должен совпадать с путём на старом диске — на этом держится перенос. */
final class DocumentFileStorageTest extends TestCase
{
    public function testAttachmentKeyAddsDocumentFolder(): void
    {
        $file = (new File())->setDocument($this->document(42))->setFilePath('a.pdf');

        self::assertSame('originals/42/a.pdf', DocumentFileStorage::attachmentKey($file));
    }

    public function testLegacyAttachmentPathKeepsItsFolder(): void
    {
        $file = (new File())->setDocument($this->document(42))->setFilePath('7/a.pdf');

        self::assertSame('originals/7/a.pdf', DocumentFileStorage::attachmentKey($file));
    }

    public function testCommentFileKey(): void
    {
        $comment = (new DocumentComment())->setDocument($this->document(42));
        $file = (new DocumentCommentFile())->setComment($comment)->setStorageKey('b.docx');

        self::assertSame('comments/42/b.docx', DocumentFileStorage::commentFileKey($file));
    }

    public function testNoFileNoKey(): void
    {
        self::assertNull(DocumentFileStorage::attachmentKey((new File())->setDocument($this->document(42))));
    }

    private function document(int $id): Document
    {
        $document = new Document();
        (new \ReflectionProperty(Document::class, 'id'))->setValue($document, $id);

        return $document;
    }
}
