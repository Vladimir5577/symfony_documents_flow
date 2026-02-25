<?php

namespace App\Controller\Document;

use App\Entity\Document;
use App\Entity\File;
use App\Repository\DocumentRepository;
use App\Repository\FileRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class DocumentFileController extends AbstractController
{
    #[Route('/document_upload_files_action/{id}', name: 'document_upload_files_action', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function new(Request $request, int $id, DocumentRepository $documentRepository, \Doctrine\ORM\EntityManagerInterface $entityManager): Response
    {
        $document = $documentRepository->find($id);
        if (!$document) {
            throw $this->createNotFoundException('Документ не найден.');
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

        $filePath = $fileEntity->getFilePath();
        if (!$filePath) {
            throw $this->createNotFoundException('Файл не прикреплён.');
        }

        $documentId = $fileEntity->getDocument()?->getId();
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

        $inline = $request->query->getBoolean('inline');

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

        $response->headers->set('Content-Type', mime_content_type($absolutePath) ?: 'application/octet-stream');
        $response->headers->set('Content-Disposition', ($inline ? 'inline' : 'attachment').'; filename="'.addslashes($filename).'"');

        return $response;
    }

    #[Route('/document_file_delete/{id}', name: 'document_file_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, FileRepository $fileRepository, DocumentRepository $documentRepository, \Doctrine\ORM\EntityManagerInterface $entityManager): Response
    {
        $fileEntity = $fileRepository->find($id);
        if (!$fileEntity instanceof File) {
            throw $this->createNotFoundException('Файл не найден.');
        }

        $documentId = $fileEntity->getDocument()?->getId();

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
