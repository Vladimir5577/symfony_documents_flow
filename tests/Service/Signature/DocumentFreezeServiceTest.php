<?php

declare(strict_types=1);

namespace App\Tests\Service\Signature;

use App\Entity\Document\Document;
use App\Service\Document\Signature\DocumentFreezeService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DocumentFreezeServiceTest extends TestCase
{
    private const SOFFICE_BINARY = '/usr/bin/soffice';

    private string $originalsDir;
    private string $canonicalDir;
    private EntityManagerInterface&MockObject $entityManager;
    private DocumentFreezeService $service;

    protected function setUp(): void
    {
        $baseDir = sys_get_temp_dir() . '/freeze_test_' . bin2hex(random_bytes(4));
        $this->originalsDir = $baseDir . '/originals';
        $this->canonicalDir = $baseDir . '/canonical';
        mkdir($this->originalsDir, 0777, true);
        mkdir($this->canonicalDir, 0777, true);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->service = new DocumentFreezeService(
            $this->entityManager,
            $this->originalsDir,
            $this->canonicalDir,
        );
    }

    protected function tearDown(): void
    {
        foreach ([$this->originalsDir, $this->canonicalDir] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        rmdir(dirname($this->originalsDir));
    }

    public function testFreezePdfCopiesFileWritesHashAndVerificationCode(): void
    {
        $originalName = bin2hex(random_bytes(16)) . '.pdf';
        copy(__DIR__ . '/Fixtures/sample.pdf', $this->originalsDir . '/' . $originalName);

        $document = new Document();
        $document->setOriginalFile($originalName);

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->freeze($document);

        self::assertNotNull($document->getCanonicalFile());
        self::assertStringEndsWith('.pdf', $document->getCanonicalFile());

        $canonicalPath = $this->canonicalDir . '/' . $document->getCanonicalFile();
        self::assertFileExists($canonicalPath);
        self::assertSame(hash_file('sha256', $canonicalPath), $document->getCanonicalFileHash());
        self::assertSame(
            hash_file('sha256', __DIR__ . '/Fixtures/sample.pdf'),
            $document->getCanonicalFileHash(),
        );

        self::assertNotNull($document->getVerificationCode());
        self::assertSame(16, strlen($document->getVerificationCode()));
        self::assertTrue(ctype_xdigit($document->getVerificationCode()));
    }

    public function testFreezeThrowsWhenDocumentAlreadyFrozen(): void
    {
        $document = new Document();
        $document->setOriginalFile('some.pdf');
        $document->setCanonicalFile('already_frozen.pdf');

        $this->entityManager->expects(self::never())->method('flush');

        $this->expectException(\LogicException::class);
        $this->service->freeze($document);
    }

    public function testFreezeThrowsWhenOriginalFileMissing(): void
    {
        $document = new Document();
        $document->setOriginalFile(bin2hex(random_bytes(16)) . '.pdf');

        $this->entityManager->expects(self::never())->method('flush');

        $this->expectException(\RuntimeException::class);
        $this->service->freeze($document);
    }

    public function testFreezeConvertsDocxToPdf(): void
    {
        if (!is_executable(self::SOFFICE_BINARY)) {
            self::markTestSkipped('LibreOffice (soffice) недоступен в окружении.');
        }

        $originalName = bin2hex(random_bytes(16)) . '.docx';
        $this->createMinimalDocx($this->originalsDir . '/' . $originalName);

        $document = new Document();
        $document->setOriginalFile($originalName);

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->freeze($document);

        $canonicalPath = $this->canonicalDir . '/' . $document->getCanonicalFile();
        self::assertFileExists($canonicalPath);
        self::assertStringStartsWith('%PDF', file_get_contents($canonicalPath, false, null, 0, 4));
        self::assertSame(hash_file('sha256', $canonicalPath), $document->getCanonicalFileHash());
        self::assertSame(16, strlen((string) $document->getVerificationCode()));
    }

    private function createMinimalDocx(string $path): void
    {
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
                <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
                <Default Extension="xml" ContentType="application/xml"/>
                <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
            </Types>
            XML);
        $zip->addFromString('_rels/.rels', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
            </Relationships>
            XML);
        $zip->addFromString('word/document.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
                <w:body>
                    <w:p><w:r><w:t>Тестовый документ</w:t></w:r></w:p>
                </w:body>
            </w:document>
            XML);
        $zip->close();
    }
}
