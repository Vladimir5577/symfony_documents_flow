<?php

declare(strict_types=1);

namespace App\Service\ApiExternal\CitizenAppeal;

use App\DTO\CitizenAppeal\CitizenAppealDto;
use App\DTO\CitizenAppeal\CitizenAppealFileDto;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\Tcpdf\Fpdi;

final class CitizenAppealPdfService
{
    private const FONT = 'dejavusans';

    private const IMAGE_EXTENSIONS = ['jpeg', 'jpg', 'png', 'gif', 'webp', 'bmp'];

    public function __construct(
        private readonly CitizenAppealApiService $citizenAppealApiService,
    ) {
    }

    public function generate(CitizenAppealDto $appeal): string
    {
        $pdf = new Fpdi('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('document_flow');
        $pdf->SetTitle('Обращение ' . $appeal->publicId);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        $this->writeTitle($pdf, 'Обращение ' . $appeal->publicId);
        $this->writeRows($pdf, [
            'Статус'      => $appeal->getStatusLabel(),
            'Дата подачи' => $appeal->createdAt->format('d.m.Y H:i'),
        ]);

        $this->writeSection($pdf, 'Данные заявителя', [
            'ФИО'     => $appeal->fio,
            'Телефон' => $appeal->phone,
            'Email'   => $appeal->email,
        ]);

        $this->writeSection($pdf, 'Обращение', [
            'Тип обращения' => $appeal->getAppealTypeLabel(),
            'Город'         => $appeal->getCityLabel(),
            'Адрес'         => $appeal->address,
        ]);

        if ($appeal->message !== null && $appeal->message !== '') {
            $this->writeSection($pdf, 'Текст обращения', [
                '' => $appeal->message,
            ]);
        }

        if ($appeal->adminComment !== null && $appeal->adminComment !== '') {
            $this->writeSection($pdf, 'Комментарий администратора', [
                '' => $appeal->adminComment,
            ]);
        }

        if (\count($appeal->files) > 0) {
            $rows = [];
            foreach ($appeal->files as $i => $file) {
                $rows[(string) ($i + 1)] = $file->originalName . ' (' . $file->getFileSizeFormatted() . ')';
            }
            $this->writeSection($pdf, 'Прикреплённые файлы (' . \count($appeal->files) . ')', $rows);

            foreach ($appeal->files as $file) {
                $this->appendFile($pdf, $file);
            }
        }

        return (string) $pdf->Output('', 'S');
    }

    private function appendFile(Fpdi $pdf, CitizenAppealFileDto $file): void
    {
        $ext = strtolower(pathinfo($file->originalName, PATHINFO_EXTENSION));

        try {
            $content = $this->citizenAppealApiService->getFileContent($file->id)['content'];
        } catch (\Throwable $e) {
            $this->appendPlaceholderPage(
                $pdf,
                $file,
                'Не удалось загрузить файл для встраивания.',
            );
            return;
        }

        if ($ext === 'pdf') {
            $this->appendPdf($pdf, $file, $content);
            return;
        }

        if (\in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            $this->appendImage($pdf, $file, $content);
            return;
        }

        $this->appendPlaceholderPage(
            $pdf,
            $file,
            'Файл этого типа нельзя встроить в PDF — он приложен к обращению отдельно.',
        );
    }

    private function appendPdf(Fpdi $pdf, CitizenAppealFileDto $file, string $content): void
    {
        try {
            $pageCount = $pdf->setSourceFile(StreamReader::createByString($content));
            for ($i = 1; $i <= $pageCount; $i++) {
                $tpl  = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
            }
        } catch (\Throwable $e) {
            $this->appendPlaceholderPage(
                $pdf,
                $file,
                'Не удалось встроить PDF-файл (возможно, повреждён или защищён).',
            );
        }
    }

    private function appendImage(Fpdi $pdf, CitizenAppealFileDto $file, string $content): void
    {
        $info = @getimagesizefromstring($content);
        if ($info === false) {
            $this->appendPlaceholderPage($pdf, $file, 'Не удалось обработать изображение.');
            return;
        }

        $pdf->AddPage();
        $this->writeFileCaption($pdf, $file);

        [$imgWidth, $imgHeight] = $info;

        $marginX    = 15;
        $top        = $pdf->GetY() + 2;
        $pageWidth  = $pdf->getPageWidth() - 2 * $marginX;
        $pageHeight = $pdf->getPageHeight() - $top - 15;

        $scale     = min($pageWidth / $imgWidth, $pageHeight / $imgHeight);
        $newWidth  = $imgWidth * $scale;
        $newHeight = $imgHeight * $scale;
        $x         = $marginX + ($pageWidth - $newWidth) / 2;

        $type = strtoupper((string) pathinfo($file->originalName, PATHINFO_EXTENSION));
        if ($type === 'JPG') {
            $type = 'JPEG';
        }

        $pdf->Image('@' . $content, $x, $top, $newWidth, $newHeight, $type);
    }

    private function appendPlaceholderPage(Fpdi $pdf, CitizenAppealFileDto $file, string $reason): void
    {
        $pdf->AddPage();
        $this->writeFileCaption($pdf, $file);

        $pdf->SetFont(self::FONT, '', 10);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->MultiCell(0, 6, $reason, 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }

    private function writeFileCaption(Fpdi $pdf, CitizenAppealFileDto $file): void
    {
        $pdf->SetFont(self::FONT, 'B', 12);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell(0, 7, 'Вложение: ' . $file->originalName, 0, 'L');
        $pdf->SetFont(self::FONT, '', 9);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->MultiCell(0, 5, $file->getFileSizeFormatted(), 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(1);
    }

    private function writeTitle(Fpdi $pdf, string $title): void
    {
        $pdf->SetFont(self::FONT, 'B', 16);
        $pdf->MultiCell(0, 10, $title, 0, 'L');
        $pdf->Ln(2);
    }

    /**
     * @param array<string, string|null> $rows
     */
    private function writeSection(Fpdi $pdf, string $heading, array $rows): void
    {
        $rows = array_filter($rows, static fn($v) => $v !== null && $v !== '');
        if (\count($rows) === 0) {
            return;
        }

        $pdf->Ln(2);
        $pdf->SetFont(self::FONT, 'B', 12);
        $pdf->MultiCell(0, 7, $heading, 0, 'L');
        $pdf->SetDrawColor(200, 200, 200);
        $y = $pdf->GetY();
        $pdf->Line(15, $y, 195, $y);
        $pdf->Ln(1);

        $this->writeRows($pdf, $rows);
    }

    /**
     * @param array<string, string|null> $rows
     */
    private function writeRows(Fpdi $pdf, array $rows): void
    {
        $labelWidth = 55;
        $valueWidth = 125;

        foreach ($rows as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $startY = $pdf->GetY();
            $startX = $pdf->GetX();

            $pdf->SetFont(self::FONT, '', 10);
            $pdf->SetTextColor(120, 120, 120);
            $pdf->MultiCell($labelWidth, 6, (string) $label, 0, 'L', false, 0);

            $pdf->SetTextColor(0, 0, 0);
            $pdf->MultiCell($valueWidth, 6, (string) $value, 0, 'L', false, 1);

            $endY = $pdf->GetY();
            if ($endY - $startY < 6) {
                $pdf->SetXY($startX, $startY + 6);
            }
        }
    }
}
