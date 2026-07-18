<?php

declare(strict_types=1);

namespace App\Tests\Service\Signature;

use App\Entity\Document\Document;
use App\Entity\Document\DocumentSignature;
use App\Entity\Document\UserCertificate;
use App\Entity\User\User;
use App\Enum\Document\SignatureLevel;
use App\Service\Document\Signature\SignedFormGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Юнит-тесты генератора печатной формы (Фаза 5, T5.1).
 */
final class SignedFormGeneratorTest extends TestCase
{
    private const PUBLIC_URL = 'http://localhost:8080';
    private const VERIFICATION_CODE = 'abcdef0123456789';

    private string $canonicalDir;
    private string $signedFormsDir;
    private SignedFormGenerator $generator;

    protected function setUp(): void
    {
        $baseDir = sys_get_temp_dir() . '/signed_form_test_' . bin2hex(random_bytes(4));
        $this->canonicalDir = $baseDir . '/canonical';
        $this->signedFormsDir = $baseDir . '/signed_forms';
        mkdir($this->canonicalDir, 0777, true);

        $this->generator = new SignedFormGenerator(
            $this->createStub(EntityManagerInterface::class),
            $this->canonicalDir,
            $this->signedFormsDir,
            self::PUBLIC_URL,
        );
    }

    protected function tearDown(): void
    {
        foreach ([$this->canonicalDir, $this->signedFormsDir] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        rmdir(dirname($this->canonicalDir));
    }

    public function testGenerateProducesValidPdfWithSignatureSheetAndQrUrl(): void
    {
        $document = $this->makeDocument();

        $fileName = $this->generator->generate($document);

        $path = $this->signedFormsDir . '/' . $fileName;
        self::assertFileExists($path);
        self::assertSame($fileName, $document->getSignedFormFile());

        $content = (string) file_get_contents($path);
        self::assertStringStartsWith('%PDF', $content, 'Файл должен быть валидным PDF по сигнатуре.');
        self::assertGreaterThan(2000, strlen($content), 'Размер PDF должен быть разумным.');

        // страниц больше, чем в каноническом файле (добавлен лист подписей)
        self::assertGreaterThan(
            $this->countPages($this->canonicalDir . '/canonical.pdf'),
            $this->countPages($path),
        );

        // QR/ссылка ведут на страницу проверки с кодом (контент без сжатия — ищем по сырым байтам)
        self::assertStringContainsString(self::PUBLIC_URL . '/verify/' . self::VERIFICATION_CODE, $content);

        // серийный номер сертификата УНЭП попадает на лист подписей (юникод-текст — проверяем
        // по сырым байтам нельзя, серийник ASCII, но пишется dejavusans; поэтому проверяем
        // отсутствие «???» здесь не требуется — контроль кириллицы см. функциональный smoke)
    }

    public function testRepeatedGenerationReplacesOldFile(): void
    {
        $document = $this->makeDocument();

        $first = $this->generator->generate($document);
        $second = $this->generator->generate($document);

        self::assertNotSame($first, $second);
        self::assertFileDoesNotExist($this->signedFormsDir . '/' . $first);
        self::assertFileExists($this->signedFormsDir . '/' . $second);
        self::assertSame($second, $document->getSignedFormFile());
    }

    public function testGenerateFailsWithoutCanonicalFile(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->generator->generate(new Document());
    }

    public function testGenerateWithManySignaturesDoesNotOverflowPage(): void
    {
        $document = $this->makeDocument(signaturesCount: 8);

        $fileName = $this->generator->generate($document);

        self::assertGreaterThan(
            $this->countPages($this->canonicalDir . '/canonical.pdf') + 1,
            $this->countPages($this->signedFormsDir . '/' . $fileName),
            'Восемь штампов не помещаются на один лист — ожидаем перенос на следующий.',
        );
    }

    // --------------------------------------------------------------- helpers

    private function makeDocument(int $signaturesCount = 2): Document
    {
        copy(__DIR__ . '/Fixtures/sample.pdf', $this->canonicalDir . '/canonical.pdf');

        $document = new Document();
        $document->setName('Тестовый документ печатной формы');
        $document->setCanonicalFile('canonical.pdf');
        $document->setCanonicalFileHash(hash_file('sha256', $this->canonicalDir . '/canonical.pdf') ?: '');
        $document->setVerificationCode(self::VERIFICATION_CODE);

        $certificate = (new UserCertificate())->setSerialNumber('1A2B3C4D5E6F');

        for ($i = 1; $i <= $signaturesCount; ++$i) {
            $signer = (new User())
                ->setLogin('signer' . $i)
                ->setLastname('Подписантов')
                ->setFirstname('Тест' . $i);

            $signature = (new DocumentSignature())
                ->setDocument($document)
                ->setSigner($signer)
                ->setDocumentHash((string) $document->getCanonicalFileHash())
                ->setSignedAt(new \DateTimeImmutable('2026-07-17 12:00:00'))
                ->setLevel($i === 1 ? SignatureLevel::ENHANCED : SignatureLevel::SIMPLE);

            if ($i === 1) {
                $signature->setCertificate($certificate)->setAlgorithm('RSA-SHA256');
            } else {
                $signature->setAlgorithm('password-confirmation');
            }

            $document->addSignature($signature);
        }

        return $document;
    }

    private function countPages(string $path): int
    {
        return (new Fpdi())->setSourceFile($path);
    }
}
