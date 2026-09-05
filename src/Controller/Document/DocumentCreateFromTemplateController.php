<?php

namespace App\Controller\Document;

use App\Entity\User\User;
use App\Repository\Document\DocumentRepository;
use App\Service\SpaApi\Documents\DocumentAccessService;
use App\Service\Document\Convertor\DocxToPdfConvertorService;
use App\Service\Document\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PhpOffice\PhpWord\TemplateProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        DocumentRepository $documentRepository,
        DocumentAccessService $accessService,
        #[Autowire('%private_upload_dir_documents_originals%')] string $originalsDir,
        #[Autowire('%private_upload_dir_documents_templates%')] string $templatesDir,
        #[Autowire('%onlyoffice_document_server_url%')] string $onlyofficeDocumentServerUrl,
        #[Autowire('%kernel.secret%')] string $secret,
    ): Response {
        $documentId = $request->query->getInt('id') ?: null;
        $requestedFilename = $request->query->get('filename');

        $documentServerBaseUrl = 'http://nginx';

        // Редактор и вместе с ним подпись callback-а выдаются только под свой
        // документ: имя оригинала берётся из документа, а не из запроса, и
        // редактировать может тот, кому документ разрешено править. Иначе любой
        // сотрудник, знающий чужое имя файла, получал легитимный callback (BE-02/14).
        $user = $this->getUser();
        if ($documentId !== null) {
            $document = $documentRepository->find($documentId);
            if ($document === null) {
                throw $this->createNotFoundException('Документ не найден.');
            }
            if (!$user instanceof User || !$accessService->canEditOutgoingDocument($document, $user)) {
                throw $this->createAccessDeniedException('Нет прав на редактирование этого документа.');
            }
            if ($requestedFilename !== null && $requestedFilename !== ''
                && basename((string) $requestedFilename) !== (string) $document->getOriginalFile()
            ) {
                throw $this->createAccessDeniedException('Файл не принадлежит документу.');
            }
        } elseif ($requestedFilename !== null && $requestedFilename !== '') {
            // Правка существующего оригинала без контекста документа — нечего подписывать.
            throw $this->createAccessDeniedException('Укажите документ.');
        }

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
            // Подпись callback-URL: /save_docx публичен для сервера OnlyOffice, и без
            // токена в него мог написать кто угодно (BE-02 / BE-14).
            'callbackToken' => self::callbackToken($filename, $secret),
        ]);
    }

    /** Внутренний адрес OnlyOffice в docker-сети — единственный хост, откуда принимаем файл. */
    private const ONLYOFFICE_INTERNAL_URL = 'http://onlyoffice:80';
    private const ONLYOFFICE_INTERNAL_HOST = 'onlyoffice';
    /** Потолок скачиваемого docx: больше — не документ, а попытка исчерпать диск/CPU LibreOffice. */
    private const MAX_DOCX_BYTES = 50 * 1024 * 1024;

    /**
     * HMAC имени файла на секрете приложения. Токен выдаётся только вместе с
     * редактором (edit_docx) и привязывает callback к конкретному файлу: чужой
     * оригинал по нему не перезаписать, а без токена callback отклоняется.
     */
    private static function callbackToken(string $filename, string $secret): string
    {
        return hash_hmac('sha256', 'save_docx:' . $filename, $secret);
    }

    private static function isValidDocxFilename(string $filename): bool
    {
        return $filename !== ''
            && $filename === basename($filename)
            && preg_match('/^[\w.\-]+\.docx$/u', $filename) === 1
            && !str_contains($filename, '..');
    }

    #[Route('/save_file_from_template_to_document', name: 'app_save_file_from_template_to_document', methods: ['POST'])]
    public function saveFileFromTemplateToDocument(
        Request $request,
        DocumentRepository $documentRepository,
        DocumentAccessService $accessService,
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

        // Привязать файл к документу может только тот, кому документ разрешено править.
        $user = $this->getUser();
        if (!$user instanceof User || !$accessService->canEditOutgoingDocument($document, $user)) {
            $this->addFlash('error', 'Нет прав на изменение этого документа.');
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

    /**
     * Callback OnlyOffice после сохранения документа.
     *
     * Путь остаётся PUBLIC_ACCESS на уровне firewall — сервер OnlyOffice ходит
     * сюда без сессии, и любая аутентификация Symfony дала бы ему 302 на /login.
     * Защита внутри (BE-02 / BE-14): HMAC-токен из edit_docx привязывает callback
     * к имени файла; содержимое скачивается только с внутреннего хоста OnlyOffice
     * через HttpClient с таймаутом, без редиректов и с потолком размера; запись
     * атомарная (tmp + rename); конвертация — после успешной записи и под try/catch.
     * Сетевой ACL на этот location — в docker_env/nginx/config/default.conf.
     */
    #[Route('/save_docx', name: 'app_save_docx', methods: ['POST'])]
    public function saveDocx(
        Request $request,
        DocxToPdfConvertorService $docxToPdfConvertorService,
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        #[Autowire('%onlyoffice_document_server_url%')] string $onlyofficeDocumentServerUrl,
        #[Autowire('%kernel.secret%')] string $secret,
    ): JsonResponse {
        $filename = (string) $request->query->get('filename', '');
        $token = (string) $request->query->get('token', '');
        if (!self::isValidDocxFilename($filename) || !hash_equals(self::callbackToken($filename, $secret), $token)) {
            $logger->warning('save_docx: отклонён callback без валидного токена', ['filename' => $filename, 'ip' => $request->getClientIp()]);

            return $this->json(['error' => 1, 'message' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['status']) || !in_array($data['status'], [2, 6], true)) {
            return $this->json(['error' => 0]);
        }

        if (empty($data['url']) || !is_string($data['url'])) {
            return $this->json(['error' => 0]);
        }

        // Публичный URL OnlyOffice → внутренний Docker-хост; после подмены принимаем
        // ТОЛЬКО его: str_replace сам по себе фильтром не является.
        $url = str_replace($onlyofficeDocumentServerUrl, self::ONLYOFFICE_INTERNAL_URL, $data['url']);
        $parts = parse_url($url);
        if (!is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || ($parts['host'] ?? '') !== self::ONLYOFFICE_INTERNAL_HOST
        ) {
            $logger->warning('save_docx: URL не с внутреннего хоста OnlyOffice', ['url' => $url]);

            return $this->json(['error' => 1]);
        }

        $originalsDir = $this->getParameter('private_upload_dir_documents_originals');
        $targetPath = $originalsDir . '/' . $filename;
        $tmpPath = $originalsDir . '/.' . $filename . '.part-' . bin2hex(random_bytes(4));

        try {
            $response = $httpClient->request('GET', $url, [
                'timeout' => 10,
                'max_duration' => 60,
                'max_redirects' => 0,
            ]);
            if ($response->getStatusCode() !== 200) {
                $logger->warning('save_docx: OnlyOffice ответил не 200', ['status' => $response->getStatusCode()]);

                return $this->json(['error' => 1]);
            }

            $handle = fopen($tmpPath, 'wb');
            if ($handle === false) {
                return $this->json(['error' => 1]);
            }
            $written = 0;
            foreach ($httpClient->stream($response) as $chunk) {
                $content = $chunk->getContent();
                $written += strlen($content);
                if ($written > self::MAX_DOCX_BYTES) {
                    fclose($handle);
                    @unlink($tmpPath);
                    $logger->warning('save_docx: файл больше потолка', ['filename' => $filename]);

                    return $this->json(['error' => 1]);
                }
                fwrite($handle, $content);
            }
            fclose($handle);
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            $logger->error('save_docx: не удалось скачать файл', ['exception' => $e]);

            return $this->json(['error' => 1]);
        }

        if ($written === 0 || !@rename($tmpPath, $targetPath)) {
            @unlink($tmpPath);

            return $this->json(['error' => 1]);
        }

        // Конвертация — только после успешной записи и не роняет ответ: битый docx
        // раньше давал ProcessFailedException и 500 вместо {"error":1}.
        try {
            $docxToPdfConvertorService->convertDocxToPdf($targetPath);
        } catch (\Throwable $e) {
            $logger->error('save_docx: конвертация в PDF не удалась', ['filename' => $filename, 'exception' => $e]);

            return $this->json(['error' => 1]);
        }

        return $this->json(['error' => 0]);
    }
}
