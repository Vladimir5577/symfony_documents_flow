<?php

namespace App\Controller\Document;

use App\Service\Document\FileUploadService;
use setasign\Fpdi\Tcpdf\Fpdi;
use App\Repository\DocumentRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class DocumentSignController extends AbstractController
{

    #[Route('/document/sign', name: 'app_document_sign')]
    public function index(): Response
    {
        return $this->render('document_sign/index.html.twig', [
            'controller_name' => 'DocumentSignController',
        ]);
    }


    // @deprecated
    #[Route('/sign_document', name: 'app_sign_document')]
    public function signDocument(): Response
    {
        $projectDir = $this->getParameter('kernel.project_dir');
        $filesDir = $projectDir . '/public/files';
        $sourcePdf = $filesDir . '/dummy_1.pdf';      // исходный документ
        $outputPdf = $filesDir . '/dummy_sign.pdf';   // файл с добавленной подписью

        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();

// ------------------------
// 1. Импортируем все страницы исходного PDF
// ------------------------
        $pageCount = $pdf->setSourceFile($sourcePdf);
        for ($i = 1; $i <= $pageCount; $i++) {
            $tpl = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($tpl);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);
        }

// ------------------------
// 2. Добавляем прямоугольный штамп с текстом внутри
// ------------------------
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 12);  // Используем шрифт для кириллицы

// Прямоугольник для штампа
        $x_base = 20; // координата X (слева)
        $y_base = 50; // координата Y (сверху)

        $stampWidth = 60; // ширина штампа
        $stampHeight = 25; // высота штампа

        $pdf->SetDrawColor(0, 0, 255);  // цвет рамки штампа (синий)
        $pdf->SetTextColor(0, 0, 255);
        $pdf->SetLineWidth(1.0);
        $pdf->Rect($x_base, $y_base, $stampWidth, $stampHeight); // рисуем прямоугольник

// Текст внутри штампа
        $text = "Подпись Bob Ston Parker\n30.01.2026";
        $pdf->SetXY($x_base + 5, $y_base + 5);  // отступаем от краёв прямоугольника
        $pdf->MultiCell($stampWidth - 10, 7, $text, 0, 'C');  // текст по центру

// ------------------------
// 3. Сохраняем PDF
// ------------------------
        $pdf->Output($outputPdf, 'F');


        return $this->render('document/history_outgoing_document.html.twig', [
            'active_tab' => 'outgoing_documents',
        ]);
    }

    #[Route('/convert_docx_to_pdf_document', name: 'app_convert_docx_to_pdf_documents')]
    public function convertDocxToPdfDocument(DocumentRepository $documentRepository): Response
    {
        $projectDir = $this->getParameter('kernel.project_dir');
        $outputDir = $projectDir . '/public/files'; // абсолютный путь
        $docxFilePath = $outputDir . '/word.docx'; // файл который загружен

// создаём временный профиль для LibreOffice
        $tmpProfile = '/tmp/libreoffice_profile';
        if (!is_dir($tmpProfile)) {
            mkdir($tmpProfile, 0777, true);
        }

        // запуск LibreOffice для конвертации DOCX → PDF
        $process = new \Symfony\Component\Process\Process([
            '/usr/bin/soffice', // путь к бинарнику LibreOffice
            '--headless',
            '--convert-to', 'pdf',
            $docxFilePath,
            '--outdir', $outputDir,
            '-env:UserInstallation=file://' . $tmpProfile
        ]);

        $process->run();

// проверка на ошибки
        if (!$process->isSuccessful()) {
            throw new \Symfony\Component\Process\Exception\ProcessFailedException($process);
        }

// путь к PDF
        $pdfFilePath = $outputDir . '/' . basename($docxFilePath, '.docx') . '.pdf';

// проверка, что файл создан
        if (!file_exists($pdfFilePath)) {
            throw new \Exception("PDF не был создан");
        }


        return $this->render('document/history_outgoing_document.html.twig', [
            'active_tab' => 'outgoing_documents',
        ]);
    }

    #[Route('/edit_pdf', name: 'app_edit_pdf')]
    public function editPdf(DocumentRepository $documentRepository): Response
    {
        return $this->render('document/test.html.twig', [
        ]);
    }

    #[Route('/create_pdf_table_executors', name: 'app_create_pdf_table_executors')]
    public function createPdfTableExecutors(DocumentRepository $documentRepository): Response
    {

        $projectDir = $this->getParameter('kernel.project_dir');
        $filesDir = $projectDir . '/public/files';
        $sourcePdf = $filesDir . '/dummy_1.pdf';
        $outputPdf = $filesDir . '/dummy_with_table.pdf';

// Список исполнителей
        $executors = [
            'Иван Иванов',
            'Мария Петрова',
            'Алексей Смирнов',
        ];

        $pdf = new Fpdi();

// ------------------------
// 1. Копируем все страницы оригинала
// ------------------------
        $pageCount = $pdf->setSourceFile($sourcePdf);
        for ($i = 1; $i <= $pageCount; $i++) {
            $tpl = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($tpl);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);
        }

// ------------------------
// 2. Добавляем последнюю страницу с таблицей
// ------------------------
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 12); // шрифт для кириллицы

// Определяем размеры таблицы
        $leftX = 20;
        $rightX = 110;
        $startY = 30;
        $rowHeight = 25;
        $tableWidthLeft = 90; // 80
        $tableWidthRight = 90;

// Рисуем заголовки
        $pdf->SetXY($leftX, $startY);
        $pdf->Cell($tableWidthLeft, $rowHeight, 'Исполнитель', 1, 0, 'C');
        $pdf->Cell($tableWidthRight, $rowHeight, 'Место для штампа', 1, 1, 'C');

// Рисуем строки
        $y = $startY + $rowHeight;
        foreach ($executors as $executor) {
            $pdf->SetXY($leftX, $y);
            $pdf->Cell($tableWidthLeft, $rowHeight, $executor, 1, 0, 'L');

            // Правая ячейка пустая (для штампа)
            $pdf->Cell($tableWidthRight, $rowHeight, '', 1, 1, 'C');

            $y += $rowHeight;
        }

// ------------------------
// 3. Сохраняем финальный PDF
// ------------------------
        $pdf->Output($outputPdf, 'F');

//        echo "PDF с таблицей сохранен: $outputPdf";


        return $this->render('document/test.html.twig', [
        ]);
    }

    #[Route('/executors_signature', name: 'app_executors_signature')]
    public function executorsSignature(
        Request $request,
        DocumentRepository $documentRepository,
        #[Autowire('%private_upload_dir_documents_originals%')] string $originalsDir,
        #[Autowire('%private_upload_dir_documents_updated%')] string $updatedDir,
    ): Response
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $document = $documentRepository->findOneWithRelations($data['id']);
        if (!$document?->getOriginalFile()) {
            throw $this->createNotFoundException('У документа нет файла для подписания.');
        }

        $sourcePdf = $originalsDir . '/' . $document->getOriginalFile();      // исходный документ
        $outputPdf = $updatedDir . '/' . $document->getOriginalFile();   // файл с добавленной подписью


        // координаты с фронта
        // x max = 150
        // y max = 267
        $stampPage = $data['page'];   // номер страницы (1-based!)
        $stampX = $data['x'] / 4;    // X из JS
        $stampY = (840 - $data['y']) / 3.15;    // Y из JS

        $pdf = new Fpdi();

        $pageCount = $pdf->setSourceFile($sourcePdf);

        for ($i = 1; $i <= $pageCount; $i++) {

            $tpl = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($tpl);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);

            // 👇 ТОЛЬКО НА НУЖНОЙ СТРАНИЦЕ
            if ($i === $stampPage) {

                $pdf->SetDrawColor(0, 0, 255);
                $pdf->SetTextColor(0, 0, 255);
                $pdf->SetLineWidth(1);

                // размеры штампа
                $stampWidth = 60;
                $stampHeight = 18;

                // Максимум x,y — размер страницы в мм (A4: 210×297). Чтобы штамп не вылезал:
                // x: 0 .. (pageWidth - stampWidth),  y: 0 .. (pageHeight - stampHeight)
                $pageW = (float) $size['width'];
                $pageH = (float) $size['height'];
                $stampX = max(0, min($stampX, $pageW - $stampWidth));
                $stampY = max(0, min($stampY, $pageH - $stampHeight));

                // прямоугольный штамп
                $pdf->Rect($stampX, $stampY, $stampWidth, $stampHeight);

                // текст внутри
                $pdf->SetFont('dejavusans', '', 10);
                $pdf->SetXY($stampX + 3, $stampY + 2);
                $pdf->MultiCell(
                    $stampWidth - 6,
                    5,
                    "Подписано\nBob Stone Parker\n30.01.2026",
                    0,
                    'C'
                );
            }
        }

        $pdf->Output($outputPdf, 'F');

        return $this->render('document/test.html.twig', [
        ]);
    }

    #[Route('/convert_img_to_pdf', name: 'app_convert_img_to_pdf')]
    public function convertImgToPdf(Request $request): Response
    {
        $projectDir = $this->getParameter('kernel.project_dir');
        $filesDir   = $projectDir . '/public/files';

        $imagePath = $filesDir . '/image.jpg';
        $outputPdf = $filesDir . '/image_converted.pdf';

// --------------------------
// Создаём FPDI без первой страницы
// --------------------------
        $pdf = new Fpdi('P', 'mm', 'A4', true, 'UTF-8', false); // последний параметр false отключает авто AddPage

// Явно добавляем страницу
        $pdf->AddPage();

// размеры картинки
        list($imgWidth, $imgHeight) = getimagesize($imagePath);

// размеры страницы PDF
        $pageWidth  = $pdf->getPageWidth();
        $pageHeight = $pdf->getPageHeight();

// масштабирование
        $scale = min($pageWidth / $imgWidth, $pageHeight / $imgHeight);

        $newWidth  = $imgWidth * $scale;
        $newHeight = $imgHeight * $scale;

// центрируем
        $x = ($pageWidth - $newWidth) / 2;
        $y = ($pageHeight - $newHeight) / 2;

// вставляем изображение
        $pdf->Image($imagePath, $x, $y, $newWidth, $newHeight);

// сохраняем PDF
        $pdf->Output($outputPdf, 'F');



        return $this->render('document/test.html.twig', [
        ]);
    }

    #[Route('/sign_and_save_document/{id}', name: 'app_sign_and_save_document')]
    public function signAndSaveDocument(
        int $id,
        DocumentRepository $documentRepository,
        FileUploadService $fileUploadService,
    ): Response
    {
        $document = $documentRepository->findOneWithRelations($id);
        if (!$document?->getOriginalFile()) {
            throw $this->createNotFoundException('У документа нет файла для подписания.');
        }

        $fileUrl = $this->generateUrl(
            'app_document_download_file',
            [
                'id' => $document->getId(),
                'type' => 'original',
                'inline' => 1,
            ]
        );

        return $this->render('document/test.html.twig', [
            'active_tab' => 'incoming_documents',
            'file_url' => $fileUrl,
            'id' => $document->getId(),
        ]);
    }
}



/*

Round sign with text in last page

$projectDir = $this->getParameter('kernel.project_dir');
        $filesDir = $projectDir . '/public/files';
        $sourcePdf = $filesDir . '/dummy_1.pdf';      // исходный документ
        $outputPdf = $filesDir . '/dummy_sign.pdf';   // файл с добавленной подписью

        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();

// ------------------------
// 1. Импортируем все страницы исходного PDF
// ------------------------
        $pageCount = $pdf->setSourceFile($sourcePdf);
        for ($i = 1; $i <= $pageCount; $i++) {
            $tpl = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($tpl);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);
        }

// ------------------------
// 2. Добавляем страницу подписи для текущего подписанта
// ------------------------
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 12);

// Текст подписи вверху страницы
        $x_text = 20;
        $y_text = 20;
        $pdf->SetXY($x_text, $y_text);
        $signerName = "Mike MilleanMol Parker";
        $signerDate = "30.01.2026";
        $signerId = "12345";

        $pdf->MultiCell(0, 8,
            "ДОКУМЕНТ ПОДПИСАН\n".
            "простой электронной подписью\n\n".
            "ФИО: {$signerName}\n".
            "Дата и время: {$signerDate}\n".
            "ID подписи: {$signerId}\n".
            "Подпись сформирована в ИС «Документооборот»."
        );

// ------------------------
// 2b. Штамп прямо под текстом, смещён вправо
// ------------------------

        $x_base = $x_text + 120;
        $y_base = $pdf->GetY() + 50; // увеличил отступ с 10 до 20 мм, чтобы не накладывался на текст

        $radius_outer = 25;
        $radius_inner = 20;

        $pdf->SetDrawColor(0, 0, 255);
        $pdf->SetTextColor(0, 0, 255);

// Внешний круг
        $pdf->SetLineWidth(0.9);
        $pdf->Circle($x_base, $y_base, $radius_outer);

// Внутренний круг
        $pdf->SetLineWidth(0.5);
        $pdf->Circle($x_base, $y_base, $radius_inner);

// Центрируем текст в штампе
        $pdf->SetXY($x_base - $radius_inner, $y_base - ($radius_inner / 1.3)); // подвинул текст немного выше центра круга
        $pdf->MultiCell(
            $radius_inner * 2,
            4,          // уменьшил высоту строки, чтобы текст компактнее
            "Документ подписан\n{$signerName}\n{$signerDate}",
            0,
            'C'
        );

// ------------------------
// 3. Сохраняем PDF
// ------------------------
        $pdf->Output($outputPdf, 'F');




*/
