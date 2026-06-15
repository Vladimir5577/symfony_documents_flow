<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\User\User;
use App\Repository\Document\DocumentRepository;
use App\Repository\Document\DocumentTypeRepository;
use App\Service\SpaApi\Documents\DocumentAccessService;
use App\Service\SpaApi\Documents\DocumentApiPresenter;
use App\Service\SpaApi\Documents\DocumentAttachmentService;
use App\Service\SpaApi\Documents\DocumentCommentService;
use App\Service\SpaApi\Documents\DocumentPublishService;
use App\Service\SpaApi\Documents\DocumentRecipientsService;
use App\Service\SpaApi\Documents\DocumentUpdateService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/documents-flow')]
final class DocumentOutgoingController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentTypeRepository $documentTypeRepository,
        private readonly DocumentApiPresenter $presenter,
        private readonly DocumentAccessService $accessService,
        private readonly DocumentCommentService $commentService,
        private readonly DocumentUpdateService $updateService,
        private readonly DocumentPublishService $publishService,
        private readonly DocumentRecipientsService $recipientsService,
        private readonly DocumentAttachmentService $attachmentService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/outgoing', name: 'spa_api_documents_flow_outgoing_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $page = max(1, $request->query->getInt('page', 1));
        $pageSize = max(1, min(100, $request->query->getInt('page_size', 10)));

        $typeIdParam = trim((string) $request->query->get('type_id', ''));
        $typeId = $typeIdParam !== '' ? (int) $typeIdParam : null;

        $filters = [
            'typeId' => $typeId,
            'name' => trim((string) $request->query->get('name', '')) ?: null,
        ];

        $pagination = $this->documentRepository->findPaginatedByCreatedBy($user, $page, $pageSize, $filters);

        return $this->json([
            'items' => array_map(
                fn ($document) => $this->presenter->presentDocumentListItem($document),
                $pagination['documents'],
            ),
            'pagination' => $this->presenter->presentPagination(
                $pagination['page'],
                $pagination['limit'],
                $pagination['total'],
            ),
            'filters' => [
                'types' => array_map(
                    fn ($type) => $this->presenter->presentType($type),
                    $this->documentTypeRepository->findBy([], ['name' => 'ASC']),
                ),
            ],
        ]);
    }

    #[Route('/outgoing/{id}', name: 'spa_api_documents_flow_outgoing_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function view(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->documentRepository->findOneWithRelations($id);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessService->canViewDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $split = $this->presenter->splitRecipientsByRole($document->getUserRecipients()->toArray());

        return $this->json([
            'document' => $this->presenter->presentDocumentListItem($document),
            'executors' => $split['executors'],
            'recipients' => $split['recipients'],
            'files' => $this->attachmentService->presentFilesForDocument($document),
            'comments' => $this->commentService->presentCommentsForDocument($document->getId(), $user),
            'permissions' => $this->accessService->presentPermissions($document, $user),
            'statusChoices' => $this->presenter->presentCreationStatusChoices(),
        ]);
    }

    #[Route('/outgoing/{id}', name: 'spa_api_documents_flow_outgoing_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->findOutgoingDocument($id, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        $payload = $this->decodeJsonBody($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        try {
            $document = $this->updateService->update($document, $payload, $user);
        } catch (HttpException $e) {
            return $this->jsonError($e);
        }

        return $this->json(['document' => $this->presenter->presentDocumentListItem($document)]);
    }

    #[Route('/outgoing/{id}/publish', name: 'spa_api_documents_flow_outgoing_publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function publish(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->findOutgoingDocument($id, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        try {
            $document = $this->publishService->publish($document, $user);
        } catch (HttpException $e) {
            return $this->jsonError($e);
        }

        return $this->json(['document' => $this->presenter->presentDocumentListItem($document)]);
    }

    #[Route('/outgoing/{id}/recipients', name: 'spa_api_documents_flow_outgoing_recipients', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function updateRecipients(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->findOutgoingDocument($id, $user);
        if ($document instanceof JsonResponse) {
            return $document;
        }

        if (!$this->accessService->canEditOutgoingDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = $this->decodeJsonBody($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $executorUserIds = $payload['executorUserIds'] ?? [];
        $recipientUserIds = $payload['recipientUserIds'] ?? [];
        if (!is_array($executorUserIds)) {
            $executorUserIds = [];
        }
        if (!is_array($recipientUserIds)) {
            $recipientUserIds = [];
        }

        $this->recipientsService->replaceRecipients(
            $document,
            $this->recipientsService->normalizeUserIds($executorUserIds),
            $this->recipientsService->normalizeUserIds($recipientUserIds),
        );
        $this->entityManager->flush();

        $split = $this->presenter->splitRecipientsByRole($document->getUserRecipients()->toArray());

        return $this->json([
            'document' => $this->presenter->presentDocumentListItem($document),
            'executors' => $split['executors'],
            'recipients' => $split['recipients'],
            'permissions' => $this->accessService->presentPermissions($document, $user),
        ]);
    }

    private function findOutgoingDocument(int $id, User $user): \App\Entity\Document\Document|JsonResponse
    {
        $document = $this->documentRepository->findOneWithRelations($id);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessService->canViewDocument($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        return $document;
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function decodeJsonBody(Request $request): array|JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        return $payload;
    }

    private function jsonError(HttpException $e): JsonResponse
    {
        $message = $e->getMessage();

        return $this->json(
            ['error' => $message !== '' ? $message : SpaApiError::DOCUMENT_VALIDATION_FAILED],
            $e->getStatusCode(),
        );
    }
}
