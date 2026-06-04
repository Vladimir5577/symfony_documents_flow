<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\User\User;
use App\Repository\Document\DocumentRepository;
use App\Repository\Document\DocumentTypeRepository;
use App\Service\SpaApi\Documents\DocumentAccessService;
use App\Service\SpaApi\Documents\DocumentApiPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
            'permissions' => $this->accessService->presentPermissions($document, $user),
            'statusChoices' => $this->presenter->presentCreationStatusChoices(),
        ]);
    }
}
