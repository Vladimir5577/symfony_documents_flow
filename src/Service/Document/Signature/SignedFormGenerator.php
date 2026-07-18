<?php

declare(strict_types=1);

namespace App\Service\Document\Signature;

use App\Entity\Document\Document;
use App\Entity\Document\DocumentSignature;
use App\Enum\Document\SignatureLevel;
use Doctrine\ORM\EntityManagerInterface;
use setasign\Fpdi\Tcpdf\Fpdi;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Печатная форма подписанного документа (Фаза 5, T5.1):
 * все страницы канонического PDF + лист подписей со штампами и QR-кодом
 * на страницу проверки {APP_PUBLIC_URL}/verify/{verificationCode}.
 *
 * Идемпотентен: при повторном вызове файл пересоздаётся, старый удаляется.
 */
final class SignedFormGenerator
{
    private const FONT = 'dejavusans'; // юникод-шрифт TCPDF — кириллица без «???»
    private const MARGIN = 15;
    private const STAMP_HEIGHT = 34;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%private_upload_dir_documents_canonical%')]
        private readonly string $documentsCanonicalDir,
        #[Autowire('%private_upload_dir_documents_signed_forms%')]
        private readonly string $signedFormsDir,
        #[Autowire('%app_public_url%')]
        private readonly string $appPublicUrl,
    ) {
    }

    /**
     * Генерирует печатную форму, сохраняет в signed_forms-директорию,
     * пишет имя файла в Document::signedFormFile. Возвращает имя файла.
     */
    public function generate(Document $document): string
    {
        $canonicalFile = $document->getCanonicalFile();
        if ($canonicalFile === null) {
            throw new \RuntimeException('У документа нет канонического файла — печатную форму не из чего собрать.');
        }

        $canonicalPath = $this->documentsCanonicalDir . \DIRECTORY_SEPARATOR . basename($canonicalFile);
        if (!is_file($canonicalPath)) {
            throw new \RuntimeException('Канонический файл документа не найден: ' . basename($canonicalFile));
        }

        $pdfBinary = $this->render($document, $canonicalPath);

        if (!is_dir($this->signedFormsDir)) {
            mkdir($this->signedFormsDir, 0775, true);
        }

        $fileName = bin2hex(random_bytes(16)) . '.pdf';
        file_put_contents($this->signedFormsDir . \DIRECTORY_SEPARATOR . $fileName, $pdfBinary);

        $previous = $document->getSignedFormFile();
        if ($previous !== null) {
            $previousPath = $this->signedFormsDir . \DIRECTORY_SEPARATOR . basename($previous);
            if (is_file($previousPath)) {
                unlink($previousPath);
            }
        }

        $document->setSignedFormFile($fileName);
        $this->entityManager->flush();

        return $fileName;
    }

    private function render(Document $document, string $canonicalPath): string
    {
        $pdf = new Fpdi('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('document_flow');
        $pdf->SetTitle('Печатная форма документа');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $pdf->SetAutoPageBreak(false);
        // без сжатия потоков: контент (в т.ч. URL проверки) проверяем по сырым байтам в тестах
        $pdf->setCompression(false);

        // все страницы канонического PDF
        $pageCount = $pdf->setSourceFile($canonicalPath);
        for ($i = 1; $i <= $pageCount; ++$i) {
            $template = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
        }

        // лист подписей
        $pdf->AddPage('P', 'A4');
        $pdf->SetFont(self::FONT, 'B', 16);
        $pdf->MultiCell(0, 10, 'Лист подписей', 0, 'C');
        $pdf->Ln(2);

        $pdf->SetFont(self::FONT, '', 10);
        $pdf->MultiCell(0, 6, 'Документ: ' . (string) $document->getName(), 0, 'L');
        $pdf->MultiCell(0, 6, '№ документа: ' . ($document->getId() ?? '—'), 0, 'L');
        $pdf->MultiCell(0, 6, 'Хэш SHA-256: ' . (string) $document->getCanonicalFileHash(), 0, 'L');
        $pdf->Ln(4);

        foreach ($document->getSignatures() as $signature) {
            $this->drawStamp($pdf, $signature);
        }

        $this->drawQrBlock($pdf, $document);

        return (string) $pdf->Output('', 'S');
    }

    private function drawStamp(Fpdi $pdf, DocumentSignature $signature): void
    {
        // QR-блоку внизу нужно ~45 мм — переносим штамп на новую страницу заранее
        if ($pdf->GetY() + self::STAMP_HEIGHT > $pdf->getPageHeight() - self::MARGIN - 50) {
            $pdf->AddPage('P', 'A4');
        }

        $x = self::MARGIN;
        $y = $pdf->GetY();
        $width = $pdf->getPageWidth() - 2 * self::MARGIN;

        $pdf->SetDrawColor(0, 70, 160);
        $pdf->SetTextColor(0, 70, 160);
        $pdf->RoundedRect($x, $y, $width, self::STAMP_HEIGHT, 2);

        $pdf->SetXY($x + 4, $y + 3);
        $pdf->SetFont(self::FONT, 'B', 10);
        $pdf->MultiCell($width - 8, 6, 'ДОКУМЕНТ ПОДПИСАН ЭЛЕКТРОННОЙ ПОДПИСЬЮ', 0, 'C');

        $signer = $signature->getSigner();
        $fullName = $signer !== null
            ? trim(sprintf(
                '%s %s %s',
                (string) $signer->getLastname(),
                (string) $signer->getFirstname(),
                (string) ($signer->getPatronymic() ?? ''),
            ))
            : '';

        $lines = [
            'Вид подписи: ' . ($signature->getLevel()?->getLabel() ?? '—'),
            'Подписант: ' . ($fullName !== '' ? $fullName : '—'),
            'Дата и время: ' . ($signature->getSignedAt()?->format('d.m.Y H:i:s') ?? '—'),
        ];
        if ($signature->getLevel() === SignatureLevel::ENHANCED) {
            $lines[] = 'Сертификат №: ' . ((string) $signature->getCertificate()?->getSerialNumber() ?: '—');
        }

        $pdf->SetFont(self::FONT, '', 9);
        foreach ($lines as $i => $line) {
            $pdf->SetXY($x + 4, $y + 10 + $i * 5.5);
            $pdf->MultiCell($width - 8, 5, $line, 0, 'L');
        }

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetY($y + self::STAMP_HEIGHT + 4);
    }

    private function drawQrBlock(Fpdi $pdf, Document $document): void
    {
        $url = rtrim($this->appPublicUrl, '/') . '/verify/' . (string) $document->getVerificationCode();

        $qrSize = 32;
        $y = $pdf->getPageHeight() - self::MARGIN - $qrSize;
        if ($pdf->GetY() > $y) {
            $pdf->AddPage('P', 'A4');
            $y = $pdf->getPageHeight() - self::MARGIN - $qrSize;
        }

        $pdf->write2DBarcode($url, 'QRCODE,M', self::MARGIN, $y, $qrSize, $qrSize, [
            'border' => false,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => false,
        ]);

        $pdf->SetFont(self::FONT, '', 9);
        $pdf->SetXY(self::MARGIN + $qrSize + 5, $y + $qrSize / 2 - 6);
        $pdf->MultiCell(0, 5, 'Проверка подписи:', 0, 'L');
        // helvetica — core-шрифт: URL (ASCII) попадает в поток PDF литерально
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(self::MARGIN + $qrSize + 5, $pdf->GetY());
        $pdf->SetTextColor(0, 70, 160);
        $pdf->Cell(0, 5, $url, 0, 0, 'L', false, $url);
        $pdf->SetTextColor(0, 0, 0);
    }
}
