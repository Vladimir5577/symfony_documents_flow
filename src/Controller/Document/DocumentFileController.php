<?php

namespace App\Controller\Document;

use App\Entity\Document\Document;
use App\Entity\Document\File;
use App\Entity\User\User;
use App\Repository\Document\DocumentRepository;
use App\Repository\Document\FileRepository;
use App\Service\SpaApi\Documents\DocumentAccessService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Вложения документа в легаси-портале.
 *
 * Политика доступа та же, что у карточки документа (DocumentAccessService):
 * читать вложения могут админ, автор и получатели, прикладывать — те же, удалять —
 * админ и автор. Раньше файл искался по глобальному id без проверки, чей он
 * (BE-06 / SEC-06), и любой сотрудник скачивал любое вложение перебором номера.
 */
final class DocumentFileController extends AbstractController
{
    /**
     * Что можно открыть прямо в браузере. Всё остальное — только как attachment:
     * HTML/SVG, отданные inline на origin портала, исполняют скрипт в сессии
     * сотрудника (stored XSS).
     */
    private const INLINE_MIME_ALLOWLIST = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly DocumentAccessService $accessService,
    ) {
    }

    #[Route('/document_upload_files_action/{id}', name: 'document_upload_files_action', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function new(Request $request, int $id, DocumentRepository $documentRepository, \Doctrine\ORM\EntityManagerInterface $entityManager): Response
    {
        $document = $documentRepository->findOneWithRelations($id);
        if (!$document) {
            throw $this->createNotFoundException('Документ не найден.');
        }

        // Форма уже отдаёт токен 'document_upload_files', но он нигде не проверялся.
        $token = (string) ($request->request->get('_token') ?? $request->headers->get('X-CSRF-Token') ?? '');
        if (!$this->isCsrfTokenValid('document_upload_files', $token)) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => 'Неверный токен. Обновите страницу.'], Response::HTTP_FORBIDDEN);
            }
            $this->addFlash('error', 'Неверный токен. Обновите страницу и повторите.');

            return $this->redirectToRoute('app_view_outgoing_document', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User || !$this->accessService->canViewDocument($document, $currentUser)) {
            throw $this->createAccessDeniedException('Нет доступа к этому документу.');
        }

        $baseUploadDir = $this->getParameter('private_upload_dir_documents_originals');
        $documentDir = $baseUploadDir . '/' . $id;
        if (!is_dir($documentDir)) {
            mkdir($documentDir, 0755, true);
        }

        $uploadedFiles = $request->files->get('file') ?? $request->files->get('files');
        if (!\is_array($uploadedFiles)) {
            $uploadedFiles = $uploadedFiles ? [$uploadedFiles] : [];
        }

        $existingNames = [];
        foreach ($document->getFiles() as $existingFile) {
            $path = $existingFile->getFilePath();
            if ($path) {
                $base = $existingFile->getTitle() ?: pathinfo($path, PATHINFO_FILENAME);
                $ext = pathinfo($path, PATHINFO_EXTENSION);
                $existingNames[] = $ext ? $base . '.' . $ext : $base;
            }
        }
        $existingNames = array_map('strtolower', $existingNames);

        $count = 0;
        $duplicateCount = 0;
        foreach ($uploadedFiles as $uploadedFile) {
            if (!$uploadedFile instanceof UploadedFile) {
                continue;
            }
            $clientName = $uploadedFile->getClientOriginalName();
            if (\in_array(strtolower($clientName), $existingNames, true)) {
                ++$duplicateCount;
                continue;
            }
            $fileEntity = new File();
            $fileEntity->setDocument($document);
            $fileEntity->setFile($uploadedFile);
            $fileEntity->setTitle(pathinfo($clientName, PATHINFO_FILENAME));
            $document->addFile($fileEntity);
            $entityManager->persist($fileEntity);
            ++$count;
            $existingNames[] = strtolower($clientName);
        }

        if ($count > 0) {
            $document->setUpdatedAt(new \DateTimeImmutable());
            $entityManager->flush();
            $flashMsg = $count === 1 ? 'Файл прикреплён к документу.' : sprintf('Прикреплено файлов: %d.', $count);
            if ($duplicateCount > 0) {
                $flashMsg .= ' ' . ($duplicateCount === 1 ? '1 дубликат пропущен.' : sprintf('Дубликатов пропущено: %d.', $duplicateCount));
            }
            $this->addFlash('success', $flashMsg);
        } elseif ($duplicateCount > 0) {
            $this->addFlash('warning', $duplicateCount === 1 ? 'Файл уже прикреплён к документу.' : sprintf('Все выбранные файлы (%d) уже прикреплены.', $duplicateCount));
        }

        $message = 'Нет файлов для загрузки.';
        if ($count > 0) {
            $message = $count === 1 ? 'Файл прикреплён.' : sprintf('Прикреплено файлов: %d.', $count);
            if ($duplicateCount > 0) {
                $message .= ' ' . ($duplicateCount === 1 ? 'Дубликат пропущен.' : sprintf('Дубликатов пропущено: %d.', $duplicateCount));
            }
        } elseif ($duplicateCount > 0) {
            $message = $duplicateCount === 1 ? 'Файл уже прикреплён.' : sprintf('Все файлы (%d) уже прикреплены.', $duplicateCount);
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'success' => true,
                'count' => $count,
                'duplicateCount' => $duplicateCount,
                'message' => $message,
            ], Response::HTTP_OK);
        }

        return $this->redirectToRoute('app_view_outgoing_document', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/document_file_download/{id}', name: 'document_file_download', requirements: ['id' => '\d+'])]
    public function download(int $id, Request $request, FileRepository $fileRepository): Response
    {
        $fileEntity = $fileRepository->find($id);
        if (!$fileEntity instanceof File) {
            throw $this->createNotFoundException('Файл не найден.');
        }

        // Object-level проверка: файл отдаётся только участнику его документа.
        $document = $fileEntity->getDocument();
        $currentUser = $this->getUser();
        if (!$document instanceof Document || !$currentUser instanceof User || !$this->accessService->canViewDocument($document, $currentUser)) {
            throw $this->createAccessDeniedException('Нет доступа к этому файлу.');
        }

        $filePath = $fileEntity->getFilePath();
        if (!$filePath) {
            throw $this->createNotFoundException('Файл не прикреплён.');
        }

        $documentId = $document->getId();
        $uploadDir = $this->getParameter('private_upload_dir_documents_originals');
        $absolutePath = str_contains($filePath, '/')
            ? $uploadDir . '/' . $filePath
            : $uploadDir . '/' . $documentId . '/' . $filePath;

        if (!is_file($absolutePath)) {
            throw $this->createNotFoundException('Файл не найден на диске.');
        }

        $filename = $fileEntity->getTitle()
            ? $fileEntity->getTitle().'.'.pathinfo($filePath, PATHINFO_EXTENSION)
            : $filePath;

        $response = new StreamedResponse(static function () use ($absolutePath) {
            $handle = fopen($absolutePath, 'rb');
            if ($handle === false) {
                return;
            }
            while (!feof($handle)) {
                echo fread($handle, 8192);
                flush();
            }
            fclose($handle);
        });

        // Тип берём по содержимому, но inline разрешаем только безопасным типам;
        // остальное — attachment с octet-stream, чтобы браузер не угадывал.
        $mime = mime_content_type($absolutePath) ?: 'application/octet-stream';
        $inlineAllowed = in_array($mime, self::INLINE_MIME_ALLOWLIST, true);
        $inline = $request->query->getBoolean('inline') && $inlineAllowed;

        $response->headers->set('Content-Type', $inlineAllowed ? $mime : 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
            $this->asciiFallback($filename),
        ));

        return $response;
    }

    /** ASCII-версия имени для старых клиентов; Symfony сам добавит filename* в UTF-8. */
    private function asciiFallback(string $filename): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?? 'file';
        $ascii = str_replace(['"', '\\', '%', '/'], '_', $ascii);

        return $ascii !== '' ? $ascii : 'file';
    }

    #[Route('/document_file_delete/{id}', name: 'document_file_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, FileRepository $fileRepository, DocumentRepository $documentRepository, \Doctrine\ORM\EntityManagerInterface $entityManager): Response
    {
        $fileEntity = $fileRepository->find($id);
        if (!$fileEntity instanceof File) {
            throw $this->createNotFoundException('Файл не найден.');
        }

        // Удаляет админ или автор документа — как правка самого документа.
        $ownerDocument = $fileEntity->getDocument();
        $currentUser = $this->getUser();
        if (!$ownerDocument instanceof Document || !$currentUser instanceof User || !$this->accessService->canEditOutgoingDocument($ownerDocument, $currentUser)) {
            throw $this->createAccessDeniedException('Удалять вложения может только автор документа.');
        }

        $documentId = $ownerDocument->getId();

        $csrfToken = 'document_file_delete_'.$id;
        if (!$this->isCsrfTokenValid($csrfToken, $request->request->get('_token'))) {
            $this->addFlash('error', 'Неверный токен. Попробуйте снова.');
            return $this->redirectToRoute('app_view_outgoing_document', ['id' => $documentId], Response::HTTP_SEE_OTHER);
        }

        $uploadDir = $this->getParameter('private_upload_dir_documents_originals');
        $filePath = $fileEntity->getFilePath();
        $absolutePath = ($filePath && str_contains($filePath, '/'))
            ? $uploadDir . '/' . $filePath
            : ($documentId ? $uploadDir . '/' . $documentId . '/' . $filePath : $uploadDir . '/' . $filePath);
        if ($filePath && is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        $entityManager->remove($fileEntity);
        if ($documentId) {
            $document = $documentRepository->find($documentId);
            if ($document instanceof Document) {
                $document->setUpdatedAt(new \DateTimeImmutable());
            }
        }
        $entityManager->flush();

        $this->addFlash('success', 'Файл удалён.');
        return $this->redirectToRoute('app_view_outgoing_document', ['id' => $documentId], Response::HTTP_SEE_OTHER);
    }
}
