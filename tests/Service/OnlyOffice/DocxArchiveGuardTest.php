<?php

declare(strict_types=1);

namespace App\Tests\Service\OnlyOffice;

use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Service\OnlyOffice\DocxArchiveGuard;
use App\Service\Purchase\PurchaseFileCoEditing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class DocxArchiveGuardTest extends KernelTestCase
{
    private const XML = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:ins><w:r><w:t>Kept</w:t></w:r></w:ins><w:del><w:r><w:delText>Removed</w:delText></w:r></w:del></w:p></w:body></w:document>';

    protected function setUp(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('The ZIP extension is required.');
        }
    }

    public function testNormalDocumentAndAcceptedCopyRemainReadable(): void
    {
        $content = $this->archive(['word/document.xml' => self::XML]);
        DocxArchiveGuard::validateContent($content);

        $accepted = PurchaseFileCoEditing::acceptedCopy($content);
        DocxArchiveGuard::validateContent($accepted);
        $path = tempnam(sys_get_temp_dir(), 'docx-test');
        self::assertNotFalse($path);
        $zip = new \ZipArchive();
        try {
            file_put_contents($path, $accepted);
            self::assertTrue($zip->open($path));
            $xml = DocxArchiveGuard::read($zip, 'word/document.xml');
            self::assertStringContainsString('Kept', $xml);
            self::assertStringNotContainsString('Removed', $xml);
            self::assertStringNotContainsString('<w:ins', $xml);
            $zip->close();
        } finally {
            @unlink($path);
        }
    }

    public function testInvalidArchiveIsRejected(): void
    {
        $this->assertHttpStatus(422, static fn () => DocxArchiveGuard::validateContent('not a ZIP'));
    }

    public function testNonDocxContractDownloadIsUnchanged(): void
    {
        $pdf = '%PDF-1.7 non-DOCX contract';
        self::assertSame($pdf, PurchaseFileCoEditing::acceptedCopy($pdf));
    }

    public function testArchiveWithoutDocumentIsRejected(): void
    {
        $content = $this->archive(['other.txt' => 'not a DOCX']);
        $this->assertHttpStatus(422, static fn () => DocxArchiveGuard::validateContent($content));
    }

    public function testOversizedXmlIsRejectedByBothContentValidationAndDownload(): void
    {
        $content = $this->archive(['word/document.xml' => DocxArchiveGuard::MAX_ENTRY_BYTES + 1]);
        self::assertLessThan(100 * 1024, strlen($content));

        $this->assertHttpStatus(413, static fn () => DocxArchiveGuard::validateContent($content));
        $this->assertHttpStatus(413, static fn () => PurchaseFileCoEditing::acceptedCopy($content));
    }

    public function testTotalUnpackedSizeIsCheckedEvenForUnusedEntries(): void
    {
        $entries = ['word/document.xml' => self::XML];
        for ($index = 0; $index < DocxArchiveGuard::MAX_TOTAL_BYTES / DocxArchiveGuard::MAX_ENTRY_BYTES; ++$index) {
            $entries['word/media/' . $index . '.bin'] = DocxArchiveGuard::MAX_ENTRY_BYTES;
        }
        $content = $this->archive($entries);

        $this->assertHttpStatus(413, static fn () => PurchaseFileCoEditing::acceptedCopy($content));
    }

    public function testTooManyEntriesAreRejectedBeforeXmlProcessing(): void
    {
        $entries = ['word/document.xml' => self::XML];
        for ($index = 0; $index < DocxArchiveGuard::MAX_FILES; ++$index) {
            $entries['word/media/' . $index . '.bin'] = '';
        }
        $content = $this->archive($entries);

        $this->assertHttpStatus(413, static fn () => PurchaseFileCoEditing::acceptedCopy($content));
    }

    public function testCallbackCommitRejectsOversizedArchiveBeforeStorageOrDatabaseAccess(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(PurchaseFileCoEditing::class);
        $content = $this->archive(['word/document.xml' => DocxArchiveGuard::MAX_ENTRY_BYTES + 1]);

        $this->assertHttpStatus(413, static fn () => $service->commit(
            new PurchaseRequest(),
            new PurchaseRequestFile(),
            $content,
            false,
            [],
        ));
    }

    private function assertHttpStatus(int $status, callable $operation): void
    {
        try {
            $operation();
            self::fail('The unsafe DOCX archive was accepted.');
        } catch (HttpException $exception) {
            self::assertSame($status, $exception->getStatusCode());
        }
    }

    /** Integer values create compressed zero-filled entries without allocating their unpacked size in PHP. */
    private function archive(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx-test');
        self::assertNotFalse($path);
        $sources = [];
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        try {
            foreach ($entries as $name => $content) {
                if (is_int($content)) {
                    $source = tempnam(sys_get_temp_dir(), 'docx-entry');
                    self::assertNotFalse($source);
                    $sources[] = $source;
                    $stream = fopen($source, 'wb');
                    self::assertIsResource($stream);
                    self::assertTrue(ftruncate($stream, $content));
                    fclose($stream);
                    self::assertTrue($zip->addFile($source, $name));
                } else {
                    self::assertTrue($zip->addFromString($name, $content));
                }
            }
            self::assertTrue($zip->close());
            $result = file_get_contents($path);
            self::assertIsString($result);

            return $result;
        } finally {
            foreach ($sources as $source) {
                @unlink($source);
            }
            @unlink($path);
        }
    }
}
