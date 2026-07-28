<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Document;
use App\Entity\Inventory\DocumentFile;
use App\Entity\User\User;
use App\Repository\Inventory\DocumentFileRepository;
use App\Repository\Inventory\DocumentRepository;
use App\Service\SpaApi\Inventory\InventoryAccessService;
use App\Service\SpaApi\Inventory\InventoryApiPresenter;
use App\Service\SpaApi\Inventory\InventoryAttachmentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spa/api/inventory')]
final class DocumentAttachmentController extends AbstractController
{
    public function __construct(
        private readonly InventoryAttachmentService $attachments,
        private readonly InventoryAccessService $access,
        private readonly InventoryApiPresenter $presenter,
        private readonly DocumentRepository $documents,
        private readonly DocumentFileRepository $documentFiles,
    ) {
    }

    #[Route('/documents/{documentId}/attachments', methods: ['POST'])]
    #[IsGranted('ROLE_INVENTORY_MOL')]
    public function upload(int $documentId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $document = $this->findDocument($documentId);
        if (!$this->access->canManageDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        $uploaded = $request->files->get('file');
        if ($uploaded === null) {
            return $this->json(['error' => SpaApiError::INVENTORY_FILE_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $file = $this->attachments->upload($document, $uploaded, $user);

        return $this->json(['file' => $this->presenter->file($file)], Response::HTTP_CREATED);
    }

    #[Route('/attachments/{id}/download', methods: ['GET'])]
    public function download(int $id, Request $request, #[CurrentUser] User $user): BinaryFileResponse|JsonResponse
    {
        $file = $this->findFile($id);
        $document = $file->getDocument();

        if (!$this->canDownload($document, $user)) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        $response = new BinaryFileResponse($this->attachments->resolveFilePath($file));
        $response->headers->set('Content-Type', (string) $file->getContentType());
        $response->setContentDisposition(
            $request->query->getBoolean('inline') ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            (string) $file->getFilename(),
        );

        return $response;
    }

    #[Route('/attachments/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_INVENTORY_MOL')]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $file = $this->findFile($id);
        $document = $file->getDocument();
        if (!$this->access->canManageDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_DENIED_SCOPE], Response::HTTP_FORBIDDEN);
        }

        $this->attachments->delete($document, $file, $user);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function findDocument(int $id): Document
    {
        $document = $this->documents->find($id);
        if ($document === null) {
            throw $this->createNotFoundException(SpaApiError::INVENTORY_NOT_FOUND);
        }

        return $document;
    }

    private function findFile(int $id): DocumentFile
    {
        $file = $this->documentFiles->find($id);
        if ($file === null) {
            throw $this->createNotFoundException(SpaApiError::INVENTORY_NOT_FOUND);
        }

        return $file;
    }

    /**
     * Скачивание сканов: админ/МОЛ/бухгалтерия — всё; начальник отдела — документы, где
     * участвует его подразделение; сотрудник — документы, где он держатель строк.
     */
    private function canDownload(Document $document, User $user): bool
    {
        if ($this->access->canViewAll()) {
            return true;
        }

        $deptIds = $this->access->canViewDepartment() ? $this->access->departmentScopeIds($user) : [];
        foreach ($document->getLines() as $line) {
            foreach ([$line->getFromHolderUser(), $line->getToHolderUser()] as $holder) {
                if ($holder === null) {
                    continue;
                }
                if ((int) $holder->getId() === (int) $user->getId()) {
                    return true;
                }
                $orgId = $holder->getOrganization()?->getId();
                if ($orgId !== null && \in_array((int) $orgId, $deptIds, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
