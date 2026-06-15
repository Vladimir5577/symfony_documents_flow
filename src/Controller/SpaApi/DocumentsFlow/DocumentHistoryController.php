<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\User\User;
use App\Repository\Document\DocumentRepository;
use App\Service\SpaApi\Documents\DocumentHistoryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/documents-flow')]
final class DocumentHistoryController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentHistoryService $historyService,
    ) {
    }

    #[Route(
        '/incoming/{documentId}/recipients/{userId}/history',
        name: 'spa_api_documents_flow_incoming_recipient_history',
        requirements: ['documentId' => '\d+', 'userId' => '\d+'],
        methods: ['GET'],
    )]
    public function incomingRecipientHistory(
        int $documentId,
        int $userId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->documentRepository->findOneWithRelations($documentId);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        try {
            $payload = $this->historyService->getIncomingRecipientHistory($document, $user, $userId);
        } catch (HttpException $e) {
            return $this->json(
                ['error' => $e->getMessage() !== '' ? $e->getMessage() : SpaApiError::ACCESS_DENIED],
                $e->getStatusCode(),
            );
        }

        return $this->json($payload);
    }

    #[Route(
        '/outgoing/{documentId}/recipients/{userId}/history',
        name: 'spa_api_documents_flow_outgoing_recipient_history',
        requirements: ['documentId' => '\d+', 'userId' => '\d+'],
        methods: ['GET'],
    )]
    public function outgoingRecipientHistory(
        int $documentId,
        int $userId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->documentRepository->findOneWithRelations($documentId);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        try {
            $payload = $this->historyService->getOutgoingRecipientHistory($document, $user, $userId);
        } catch (HttpException $e) {
            return $this->json(
                ['error' => $e->getMessage() !== '' ? $e->getMessage() : SpaApiError::ACCESS_DENIED],
                $e->getStatusCode(),
            );
        }

        return $this->json($payload);
    }
}
