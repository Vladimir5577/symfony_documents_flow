<?php

namespace App\Controller\Document;

use App\Repository\DocumentRepository;
use App\Service\Document\Convertor\DocxToPdfConvertorService;
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
use PhpOffice\PhpWord\TemplateProcessor;

final class DocumentCreateFromTemplateController extends AbstractController
{
    #[Route('/document_create_docx_form', name: 'app_document_create_docx_form')]
    public function creteDocxForm(): Response
    {



        return $this->render('document_create_from_template/create_docx_form.html.twig', [
            'active_tab' => 'document_upload_files',
        ]);
    }

    #[Route('/document_create_from_form_action', name: 'app_document_create_from_form_action', methods: ['POST'])]
    public function createFromFormAction(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('create_docx_form', $request->request->get('_csrf_token') ?? '')) {
            $this->addFlash('error', 'Неверный токен.');
            return $this->redirectToRoute('app_document_create_docx_form');
        }

        $companyName = (string) $request->request->get('company_name', '');
        $clientName = (string) $request->request->get('client_name', '');
        $amount = (string) $request->request->get('amount', '');
        $contractDate = (string) $request->request->get('contract_date', '');

        $templatePath = $this->getParameter('kernel.project_dir') . '/public/files/word.docx';
        if (!is_readable($templatePath)) {
            $this->addFlash('error', 'Шаблон word.docx не найден.');
            return $this->redirectToRoute('app_document_create_docx_form', [], 302);
        }

        $template = new TemplateProcessor($templatePath);
        $template->setValue('company_name', $companyName);
        $template->setValue('client_name', $clientName);
        $template->setValue('amount', $amount);
        $template->setValue('contract_date', $contractDate);

        $filesDir = $this->getParameter('kernel.project_dir') . '/public/files';
        $outputFilename = 'generated_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(4)) . '.docx';
        $outputPath = $filesDir . DIRECTORY_SEPARATOR . $outputFilename;
        $template->saveAs($outputPath);

        $content = file_get_contents($outputPath);
        if ($content === false) {
            $this->addFlash('error', 'Не удалось прочитать созданный файл.');
            return $this->redirectToRoute('app_document_create_docx_form');
        }

        return new Response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="' . $outputFilename . '"',
        ]);
    }


    // ======================================================

    #[Route('/edit_docx', name: 'app_edit_docx')]
    public function editDocx(
        Request $request,
        #[Autowire('%private_upload_dir_documents_originals%')] string $originalsDir,
        #[Autowire('%private_upload_dir_documents_templates%')] string $templatesDir,
        #[Autowire('%onlyoffice_document_server_url%')] string $onlyofficeDocumentServerUrl,
    ): Response {
        $documentId = $request->query->getInt('id') ?: null;
        $requestedFilename = $request->query->get('filename');

        $documentServerBaseUrl = 'http://nginx';

        $fromTemplate = false;
        if ($requestedFilename !== null && $requestedFilename !== '') {
            $filename = basename($requestedFilename);
            $docPath = $originalsDir . DIRECTORY_SEPARATOR . $filename;
        } else {
            $filename = 'application.docx';
            $docPath = $templatesDir . DIRECTORY_SEPARATOR . $filename;
            $fromTemplate = true;
        }

        $documentVersion = file_exists($docPath) ? (string) filemtime($docPath) : (string) time();
        $documentFileUrl = $documentServerBaseUrl . $docPath . '?v=' . $documentVersion;
        $docKey = 'doc-' . random_int(100000000, 999999999);

        return $this->render('document_create_from_template/edit_docx.html.twig', [
            'filename' => $filename,
            'fileUrl' => $filename,
            'docKey' => $docKey,
            'documentFileUrl' => $documentFileUrl,
            'documentId' => $documentId,
            'fromTemplate' => $fromTemplate,
            'onlyofficeDocumentServerUrl' => $onlyofficeDocumentServerUrl,
        ]);
    }

    #[Route('/save_file_from_template_to_document', name: 'app_save_file_from_template_to_document', methods: ['POST'])]
    public function saveFileFromTemplateToDocument(
        Request $request,
        DocumentRepository $documentRepository,
        EntityManagerInterface $entityManager,
        FileUploadService $fileUploadService,
        DocxToPdfConvertorService $docxToPdfConvertorService,
        #[Autowire('%private_upload_dir_documents_originals%')] string $originalsDir,
    ): Response {
        $fromTemplate = $request->request->get('from_template');

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

        $requestedFilename = $request->request->get('filename');
        $hasFilename = $requestedFilename !== null && $requestedFilename !== '';
        $sourceFilename = $hasFilename ? basename($requestedFilename) : 'doc.docx';
        $sourceFile = $originalsDir . DIRECTORY_SEPARATOR . $sourceFilename;
        if (!is_readable($sourceFile)) {
            $this->addFlash('error', sprintf('Файл «%s» не найден. Сначала сохраните документ в редакторе.', $sourceFilename));
            return $this->redirectToRoute('app_edit_docx', ['id' => $documentId]);
        }

        if ($fromTemplate) {
            // Новый документ — создаём новый файл с уникальным именем и обновляем запись в БД
            $uniqueName = date('Y-m-d') . '_' . $fileUploadService->generateFileName() . '.docx';
            $targetPath = $originalsDir . DIRECTORY_SEPARATOR . $uniqueName;
            if (!@copy($sourceFile, $targetPath)) {
                $this->addFlash('error', 'Не удалось сохранить копию файла.');
                return $this->redirectToRoute('app_edit_docx', ['id' => $documentId]);
            }

            if ($document->getUpdatedFile()) {
                $fileUploadService->deleteUpdatedFile($document->getUpdatedFile());
            }

            // convert docx to pdf
            $docxToPdfConvertorService->convertDocxToPdf($targetPath);
            $document->setOriginalFile($uniqueName);
            $document->setUpdatedFile(pathinfo($uniqueName, \PATHINFO_FILENAME) . '.pdf');
            $entityManager->flush();
        }

        $this->addFlash('success', $hasFilename ? 'Документ обновлён.' : 'Файл прикреплён к документу.');
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
    public function saveDocx(
        Request $request,
        DocxToPdfConvertorService $docxToPdfConvertorService,
        #[Autowire('%onlyoffice_document_server_url%')] string $onlyofficeDocumentServerUrl,
    ): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

//        file_put_contents('./onlyoffice_callback.json', json_encode($data, JSON_PRETTY_PRINT));

        if (!isset($data['status']) || !in_array($data['status'], [2, 6], true)) {
            return $this->json(['error' => 0]);
        }

        if (empty($data['url'])) {
            return $this->json(['error' => 0]);
        }

        $requestedFilename = $request->query->get('filename');
        $filename = $requestedFilename !== null && $requestedFilename !== ''
            ? basename($requestedFilename)
            : 'doc.docx';

        // Сохраняем в ту же папку (originals), откуда загружается документ в редактор,
        // чтобы файл «на диске» обновлялся при автосохранении
        $targetPath = $this->getParameter('private_upload_dir_documents_originals') . '/' . $filename;

        // ⚡ Заменяем localhost на onlyoffice для Docker-сети
        // $url = str_replace('localhost:8081', 'onlyoffice:80', $data['url']);

        // ⚡ Заменяем публичный URL OnlyOffice на внутренний Docker-хост
        $url = str_replace($onlyofficeDocumentServerUrl, 'http://onlyoffice:80', $data['url']);
        $fileContent = @file_get_contents($url);

        if ($fileContent === false) {
//            file_put_contents('./onlyoffice_error.log', date('Y-m-d H:i:s') . " - Failed to download file from URL: {$url}\n", FILE_APPEND);
            return $this->json(['error' => 1]);
        }

        $result = @file_put_contents($targetPath, $fileContent);

        // convert docx to pdf at once when document saved
        $docxToPdfConvertorService->convertDocxToPdf($targetPath);

        if ($result === false) {
//            file_put_contents('./onlyoffice_error.log', date('Y-m-d H:i:s') . " - Failed to write file to path: {$targetPath}\n", FILE_APPEND);
            return $this->json(['error' => 1]);
        }

//        file_put_contents('./onlyoffice_success.log', date('Y-m-d H:i:s') . " - File saved: {$targetPath}, size: {$result} bytes\n", FILE_APPEND);

        return $this->json(['error' => 0]);
    }
}
