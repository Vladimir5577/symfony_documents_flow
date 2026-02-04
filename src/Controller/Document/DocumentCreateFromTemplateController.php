<?php

namespace App\Controller\Document;

use App\Repository\DocumentRepository;
use App\Service\Document\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class DocumentCreateFromTemplateController extends AbstractController
{
    #[Route('/document_create_from_template', name: 'app_document_create_from_template')]
    public function index(Request $request): Response
    {
        $content = '
            <p>Прошу принять меня на работу.</p>
            <p>ФИО: Иванов Иван Иванович</p>
        ';

        // если форма отправлена, подставляем новый текст
        if ($request->isMethod('POST')) {
            $content = $request->request->get('content');
        }

        return $this->render('document_create_from_template/index.html.twig', [
            'content' => $content,
            'date' => (new \DateTime())->format('d.m.Y'),
        ]);
    }

    #[Route('/document_save_from_template', name: 'app_document_save_from_template')]
    public function saveFromTemplate(
        Request $request,
        FileUploadService $fileUploadService,
        #[Autowire('%private_upload_dir_documents_originals%')] string $originalsDir,
        #[Autowire('%private_upload_dir_documents_updated%')] string $updatedDir,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ): Response
    {
        $html = $request->request->get('html');
        if (!$html) {
            return $this->json(['status' => 'error', 'message' => 'HTML не передан']);
        }

        // Конвертим в UTF-8, если надо
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8');
        }

        $baseName = date('Y-m-d_His') . '_' . $fileUploadService->generateFileName();

        $htmlFilename = $baseName . '.html';
        $htmlPath = $originalsDir . DIRECTORY_SEPARATOR . $htmlFilename;
        file_put_contents($htmlPath, $html);



        $pdfFilename = $baseName . '.pdf';
        $pdfPath = $updatedDir . DIRECTORY_SEPARATOR . $pdfFilename;

        $mpdfTempDir = $projectDir . '/var/tmp/mpdf';
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 20,
            'margin_bottom' => 20,
            'tempDir' => $mpdfTempDir,
        ]);

        $mpdf->setFooter('{PAGENO} / {nb}');

        $htmlForPdf = '<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<style>
body { font-family: DejaVu Sans, sans-serif; font-size: 12pt; margin:0; padding:0; }
</style>
</head>
<body>' . $html . '</body>
</html>';

        $htmlPath = '/uploads/documents/originals/2026-02-03_172046_6c7219907d1036448599efb865409e6f.html';
        $htmlForPdf = file_get_contents($htmlPath);


        $mpdf->WriteHTML($htmlForPdf, \Mpdf\HTMLParserMode::HTML_BODY);

//        $mpdf->SetWatermarkText('СЕКРЕТНО');
//        $mpdf->showWatermarkText = true;

        $mpdf->Output($pdfPath, Destination::FILE);

        return $this->json([
            'status' => 'ok',
            'message' => 'HTML и PDF сохранены',
            'html' => $htmlFilename,
            'pdf' => $pdfFilename,
        ]);
    }


    // ======================================================

    #[Route('/edit_docx', name: 'app_edit_docx')]
    public function editDocx(
        Request $request,
        #[Autowire('%private_upload_dir_documents_originals%')] string $originalsDir,
    ): Response {
        $documentId = $request->query->getInt('id') ?: null;
        $requestedFilename = $request->query->get('filename');
        if ($requestedFilename !== null && $requestedFilename !== '') {
            $filename = basename($requestedFilename);
            if (!str_ends_with(strtolower($filename), '.docx')) {
                $filename = 'doc.docx';
            }
            $docPath = $originalsDir . '/' . $filename;
            if (!is_readable($docPath)) {
                $filename = 'doc.docx';
                $docPath = $originalsDir . '/' . $filename;
            }
        } else {
            $filename = 'doc.docx';
            $docPath = $originalsDir . '/' . $filename;
        }
        $documentVersion = file_exists($docPath) ? (string) filemtime($docPath) : (string) time();
        $docKey = 'doc-' . random_int(100000000, 999999999);

        return $this->render('document_create_from_template/edit_docx.html.twig', [
            'filename' => $filename,
            'fileUrl' => $filename,
            'docKey' => $docKey,
            'documentVersion' => $documentVersion,
            'documentId' => $documentId,
        ]);
    }

    #[Route('/save_file_from_template_to_document', name: 'app_save_file_from_template_to_document', methods: ['POST'])]
    public function saveFileFromTemplateToDocument(
        Request $request,
        DocumentRepository $documentRepository,
        EntityManagerInterface $entityManager,
        FileUploadService $fileUploadService,
        #[Autowire('%private_upload_dir_documents_originals%')] string $originalsDir,
    ): Response {
        $documentId = (int) ($request->request->get('document_id') ?? $request->query->get('id') ?? 0);
        if ($documentId <= 0) {
            $this->addFlash('error', 'Не указан документ.');
            return $this->redirectToRoute('app_outgoing_documents');
        }

        if (!$this->isCsrfTokenValid('save_file_to_document', $request->request->get('_token') ?? '')) {
            $this->addFlash('error', 'Неверный токен.');
            return $this->redirectToRoute('app_outgoing_documents');
        }

        $document = $documentRepository->find($documentId);
        if (!$document) {
            $this->addFlash('error', 'Документ не найден.');
            return $this->redirectToRoute('app_outgoing_documents');
        }

        $sourceFile = $originalsDir . '/doc.docx';
        if (!is_readable($sourceFile)) {
            $this->addFlash('error', 'Файл doc.docx не найден. Сначала сохраните документ в редакторе.');
            return $this->redirectToRoute('app_edit_docx', ['id' => $documentId]);
        }

        $uniqueName = date('Y-m-d_His') . '_' . $fileUploadService->generateFileName() . '.docx';
        $targetPath = $originalsDir . '/' . $uniqueName;
        if (!@copy($sourceFile, $targetPath)) {
            $this->addFlash('error', 'Не удалось сохранить копию файла.');
            return $this->redirectToRoute('app_edit_docx', ['id' => $documentId]);
        }

        $document->setOriginalFile($uniqueName);
        $entityManager->flush();

        $this->addFlash('success', 'Файл прикреплён к документу.');
        return $this->redirectToRoute('app_view_outgoing_document', ['id' => $document->getId()]);
    }


    #[Route('/trigger_forcesave', name: 'app_trigger_forcesave', methods: ['POST'])]
    public function triggerForcesave(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $data = json_decode($body, true);
        $key = $data['key'] ?? null;
        if (!$key || !is_string($key)) {
            return $this->json(['error' => 1, 'message' => 'key required'], 400);
        }

        $commandUrl = 'http://onlyoffice/command?shardkey=' . rawurlencode($key);
        $payload = json_encode(['c' => 'forcesave', 'key' => $key]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($payload),
                'content' => $payload,
                'timeout' => 5,
            ],
        ]);

        $response = @file_get_contents($commandUrl, false, $context);
        if ($response === false) {
            return $this->json(['error' => 1, 'message' => 'OnlyOffice command failed']);
        }

        $result = json_decode($response, true);
        return $this->json($result ?? ['error' => 0]);
    }

    #[Route('/save_docx', name: 'app_save_docx', methods: ['POST'])]
    public function saveDocx(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        file_put_contents('./onlyoffice_callback.json', json_encode($data, JSON_PRETTY_PRINT));

        if (!isset($data['status']) || !in_array($data['status'], [2, 6], true)) {
            return $this->json(['error' => 0]);
        }

        if (empty($data['url'])) {
            return $this->json(['error' => 0]);
        }

        $filename = 'doc.docx';

        // Сохраняем в ту же папку (originals), откуда загружается документ в редактор,
        // чтобы файл «на диске» обновлялся при автосохранении
        $targetPath = $this->getParameter('private_upload_dir_documents_originals') . '/' . $filename;

        // ⚡ Заменяем localhost на onlyoffice для Docker-сети
        $url = str_replace('localhost:8081', 'onlyoffice:80', $data['url']);
        $fileContent = @file_get_contents($url);

        if ($fileContent === false) {
//            file_put_contents('./onlyoffice_error.log', date('Y-m-d H:i:s') . " - Failed to download file from URL: {$url}\n", FILE_APPEND);
            return $this->json(['error' => 1]);
        }

        $result = @file_put_contents($targetPath, $fileContent);

        if ($result === false) {
//            file_put_contents('./onlyoffice_error.log', date('Y-m-d H:i:s') . " - Failed to write file to path: {$targetPath}\n", FILE_APPEND);
            return $this->json(['error' => 1]);
        }

//        file_put_contents('./onlyoffice_success.log', date('Y-m-d H:i:s') . " - File saved: {$targetPath}, size: {$result} bytes\n", FILE_APPEND);

        return $this->json(['error' => 0]);
    }


    // ========================================================

//    #[Route('/document_save_from_template', name: 'app_document_save_from_template')]
//    public function saveFromTemplate(Request $request): Response
//    {
//        // Получаем HTML из POST
//        $html = $request->request->get('html');
//
//        if (!$html) {
//            return $this->json(['status' => 'error', 'message' => 'HTML не передан']);
//        }
//
//
//        return $this->render('document_create_from_template/index.html.twig', [
//            'date' => (new \DateTime())->format('d.m.Y'),
//        ]);
//    }
}
