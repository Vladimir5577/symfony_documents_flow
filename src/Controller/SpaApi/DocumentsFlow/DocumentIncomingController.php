<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\User\User;
use App\Enum\DocumentStatus;
use App\Repository\Document\DocumentRepository;
use App\Repository\Document\DocumentTypeRepository;
use App\Repository\Document\DocumentUserRecipientRepository;
use App\Service\SpaApi\Documents\DocumentAccessService;
use App\Service\SpaApi\Documents\DocumentApiPresenter;
use App\Service\SpaApi\Documents\DocumentDetailResponseBuilder;
use App\Service\SpaApi\Documents\DocumentRecipientStatusService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/documents-flow')]
final class DocumentIncomingController extends AbstractController
{
    public function __construct(
        private readonly DocumentUserRecipientRepository $recipientRepository,
        private readonly DocumentTypeRepository $documentTypeRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentApiPresenter $presenter,
        private readonly DocumentAccessService $accessService,
        private readonly DocumentRecipientStatusService $recipientStatusService,
        private readonly DocumentDetailResponseBuilder $detailResponseBuilder,
    ) {
    }

    #[Route('/incoming', name: 'spa_api_documents_flow_incoming_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $page = max(1, $request->query->getInt('page', 1));
        $pageSize = max(1, min(100, $request->query->getInt('page_size', 10)));

        $statusParam = trim((string) $request->query->get('status', ''));
        $status = $statusParam !== '' ? DocumentStatus::tryFrom($statusParam) : null;

        $typeIdParam = trim((string) $request->query->get('type_id', ''));
        $typeId = $typeIdParam !== '' ? (int) $typeIdParam : null;

        $filters = [
            'status' => $status,
            'typeId' => $typeId,
            'creator' => trim((string) $request->query->get('creator', '')) ?: null,
            'name' => trim((string) $request->query->get('name', '')) ?: null,
            'createdFrom' => $this->parseDate($request->query->get('created_from')),
            'createdTo' => $this->parseDateEnd($request->query->get('created_to')),
        ];

        $pagination = $this->recipientRepository->findPaginatedByUser($user, $page, $pageSize, $filters);

        return $this->json([
            'items' => array_map(
                fn ($recipient) => $this->presenter->presentIncomingListItem($recipient),
                $pagination['recipients'],
            ),
            'pagination' => $this->presenter->presentPagination(
                $pagination['page'],
                $pagination['limit'],
                $pagination['total'],
            ),
            'filters' => [
                'statusChoices' => $this->presenter->presentStatusChoices(),
                'types' => array_map(
                    fn ($type) => $this->presenter->presentType($type),
                    $this->documentTypeRepository->findBy([], ['name' => 'ASC']),
                ),
            ],
        ]);
    }

    #[Route('/incoming/{id}', name: 'spa_api_documents_flow_incoming_view', requirements: ['id' => '\d+'], methods: ['GET'])]
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

        return $this->json($this->detailResponseBuilder->buildIncomingDetail($document, $user));
    }

    #[Route('/incoming/{id}/recipient-status', name: 'spa_api_documents_flow_incoming_recipient_status', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function updateRecipientStatus(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
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

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        try {
            $document = $this->recipientStatusService->updateStatus($document, $payload, $user);
        } catch (HttpException $e) {
            $message = $e->getMessage();

            return $this->json(
                ['error' => $message !== '' ? $message : SpaApiError::DOCUMENT_INVALID_STATUS],
                $e->getStatusCode(),
            );
        }

        return $this->json($this->detailResponseBuilder->buildIncomingDetail($document, $user));
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date === false ? null : $date;
    }

    private function parseDateEnd(mixed $value): ?\DateTimeImmutable
    {
        $date = $this->parseDate($value);

        return $date?->setTime(23, 59, 59);
    }
}
