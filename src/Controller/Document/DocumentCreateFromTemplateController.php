<?php

namespace App\Controller\Document;

use App\Entity\User\User;
use App\Repository\Document\DocumentRepository;
use App\Service\Document\Convertor\DocxToPdfConvertorService;
use App\Service\Document\FileUploadService;
use App\Service\SpaApi\Documents\DocumentAccessService;
use App\Service\OnlyOffice\LegacyEditorSession;
use App\Service\OnlyOffice\OnlyOfficeCallback;
use App\Service\OnlyOffice\OnlyOfficeJwt;
use Doctrine\ORM\EntityManagerInterface;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PhpOffice\PhpWord\TemplateProcessor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DocumentCreateFromTemplateController extends AbstractController
{
    public function __construct(
        private readonly LegacyEditorSession $sessions,
        private readonly OnlyOfficeJwt $jwt,
    ) {
    }

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
        DocumentRepository $documents,
        DocumentAccessService $access,
        #[Autowire('%private_upload_dir_documents_originals%')] string $originalsDir,
        #[Autowire('%private_upload_dir_documents_templates%')] string $templatesDir,
        #[Autowire('%onlyoffice_document_server_url%')] string $onlyofficeDocumentServerUrl,
    ): Response {
        $this->jwt->requireEnabled();
        $user = $this->getUser();
        $documentId = $request->query->getInt('id') ?: null;
        $document = $documentId !== null ? $documents->find($documentId) : null;
        if (!$user instanceof User || ($documentId !== null && (!$document || !$access->canEditOutgoingDocument($document, $user)))) {
            throw $this->createAccessDeniedException();
        }
        $fromTemplate = !$document?->getOriginalFile();
        $filename = $fromTemplate ? 'application.docx' : $document->getOriginalFile();
        $docPath = $fromTemplate ? $templatesDir . DIRECTORY_SEPARATOR . $filename : $this->sessions->path($filename);
        if (!is_readable($docPath)) {
            throw $this->createNotFoundException();
        }
        $session = $this->sessions->create($user, $document, $docPath, $fromTemplate);
        $config = [
            'width' => '100%', 'height' => '100%', 'type' => 'desktop',
            'document' => [
                'fileType' => 'docx', 'key' => $session['key'], 'title' => $filename,
                'url' => 'http://nginx' . $this->generateUrl('app_docx_editor_content', ['session' => $session['id']]),
            ],
            'editorConfig' => [
                'lang' => 'ru',
                'callbackUrl' => 'http://nginx' . $this->generateUrl('app_save_docx', ['session' => $session['id']]),
                'autosave' => true, 'autosaveInterval' => 2,
                'customization' => ['forcesave' => true],
            ],
        ];
        $config['token'] = $this->jwt->encode($config + ['exp' => $session['expires']]);

        return $this->render('document_create_from_template/edit_docx.html.twig', [
            'filename' => $session['filename'],
            'sessionId' => $session['id'],
            'docKey' => $session['key'],
            'editorConfig' => $config,
            'documentId' => $documentId,
            'fromTemplate' => $fromTemplate,
            'onlyofficeDocumentServerUrl' => $onlyofficeDocumentServerUrl,
        ]);
    }

    #[Route('/docx_editor_heartbeat/{session}', name: 'app_docx_editor_heartbeat', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function editorHeartbeat(string $session, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $user = $this->getUser();
        if (!$user instanceof User || !is_array($data)
            || !$this->isCsrfTokenValid('docx_editor_heartbeat', $data['_token'] ?? null)) {
            throw $this->createAccessDeniedException();
        }
        $renewed = $this->sessions->renew($session, $user);
        if ($renewed === null) {
            return $this->json(['message' => 'Сохранение остановлено. Сессия редактора истекла или доступ к документу изменился.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['expires' => $renewed['expires']]);
    }

    #[Route('/docx_editor_content/{session}', name: 'app_docx_editor_content', methods: ['GET'])]
    public function editorContent(string $session): Response
    {
        $this->jwt->requireEnabled();
        $data = $this->sessions->get($session);
        if ($data === null) {
            throw $this->createAccessDeniedException();
        }
        $response = $this->file($this->sessions->path($data['filename']));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/save_file_from_template_to_document', name: 'app_save_file_from_template_to_document', methods: ['POST'])]
    public function saveFileFromTemplateToDocument(
        Request $request,
        DocumentRepository $documentRepository,
        EntityManagerInterface $entityManager,
        FileUploadService $fileUploadService,
        DocxToPdfConvertorService $docxToPdfConvertorService,
        DocumentAccessService $accessService,
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

        $user = $this->getUser();
        if (!$user instanceof User || !$accessService->canEditOutgoingDocument($document, $user)) {
            throw $this->createAccessDeniedException();
        }

        $session = $this->sessions->get((string) $request->request->get('session', ''));
        if ($session === null || $session['userId'] !== $user->getId()
            || ($session['documentId'] !== null && $session['documentId'] !== $documentId)) {
            throw $this->createAccessDeniedException();
        }
        $fromTemplate = $session['fromTemplate'];
        $hasFilename = !$fromTemplate;
        $sourceFilename = $session['filename'];
        $sourceFile = $this->sessions->path($sourceFilename);
        if (!is_readable($sourceFile)) {
            $this->addFlash('error', sprintf('Файл «%s» не найден. Сначала сохраните документ в редакторе.', $sourceFilename));
            return $this->redirectToRoute('app_edit_docx', ['id' => $documentId]);
        }

        if ($fromTemplate) {
            // Новый документ — создаём новый файл с уникальным именем и обновляем запись в БД
            $uniqueName = date('Y-m-d') . '_' . $fileUploadService->generateFileName() . '.docx';
            $targetPath = $this->sessions->path($uniqueName);
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
    public function triggerForcesave(
        Request $request,
        #[Autowire('%onlyoffice_internal_url%')] string $internalUrl,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isCsrfTokenValid('trigger_forcesave', $data['_token'] ?? null)) {
            throw $this->createAccessDeniedException();
        }
        $session = $this->sessions->get((string) ($data['session'] ?? ''));
        $user = $this->getUser();
        if ($session === null || !$user instanceof User || $session['userId'] !== $user->getId()
            || $session['key'] !== ($data['key'] ?? null)) {
            throw $this->createAccessDeniedException();
        }
        $key = $session['key'];
        $saveId = $this->sessions->beginSave($session['id']);
        $body = ['c' => 'forcesave', 'key' => $key, 'userdata' => $saveId];
        $body['token'] = $this->jwt->encode($body);
        $payload = json_encode($body, JSON_THROW_ON_ERROR);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($payload),
                'content' => $payload,
                'timeout' => 5,
                'follow_location' => 0,
            ],
        ]);
        $response = @file_get_contents(rtrim($internalUrl, '/') . '/command?shardkey=' . rawurlencode($key), false, $context);
        if ($response === false) {
            return $this->json(['error' => 1, 'message' => 'OnlyOffice command failed']);
        }

        $result = json_decode($response, true);
        if (!is_array($result)) {
            $result = ['error' => 1];
        }
        if (($result['error'] ?? null) === 4) {
            $this->sessions->acknowledgeSave($session['id'], $saveId);
        }

        return $this->json($result + ['saveId' => $saveId]);
    }

    #[Route('/docx_editor_save_status/{session}', name: 'app_docx_editor_save_status', methods: ['GET'])]
    public function editorSaveStatus(string $session, Request $request): JsonResponse
    {
        $data = $this->sessions->get($session);
        $user = $this->getUser();
        if ($data === null || !$user instanceof User || $data['userId'] !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        return $this->json(['saved' => $this->sessions->isSaved($session, (string) $request->query->get('saveId', ''))]);
    }

    #[Route('/save_docx', name: 'app_save_docx', methods: ['POST'])]
    public function saveDocx(
        Request $request,
        DocxToPdfConvertorService $docxToPdfConvertorService,
        OnlyOfficeCallback $callback,
    ): JsonResponse {
        $data = $callback->data($request);
        $session = $this->sessions->get((string) $request->query->get('session', ''));
        if ($data === null || $session === null || ($data['key'] ?? null) !== $session['key']) {
            return $this->json(['error' => 1], Response::HTTP_FORBIDDEN);
        }
        if (!in_array($data['status'] ?? null, [2, 6], true)) {
            return $this->json(['error' => 0]);
        }
        if (!is_string($data['url'] ?? null)) {
            return $this->json(['error' => 1], Response::HTTP_BAD_REQUEST);
        }
        $fileContent = $callback->download($data['url']);
        if ($fileContent === null) {
            return $this->json(['error' => 1], Response::HTTP_BAD_REQUEST);
        }
        $targetPath = $this->sessions->path($session['filename']);
        $temporaryPath = tempnam(dirname($targetPath), '.onlyoffice-');
        if ($temporaryPath === false) {
            return $this->json(['error' => 1], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        try {
            if (file_put_contents($temporaryPath, $fileContent, LOCK_EX) !== strlen($fileContent)
                || !rename($temporaryPath, $targetPath)) {
                return $this->json(['error' => 1], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
        $docxToPdfConvertorService->convertDocxToPdf($targetPath);
        $this->sessions->acknowledgeSave($session['id'], (string) ($data['userdata'] ?? ''));

        return $this->json(['error' => 0]);
    }
}
