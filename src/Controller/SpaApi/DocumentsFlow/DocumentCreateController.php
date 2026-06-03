<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\User\User;
use App\Service\SpaApi\Documents\DocumentApiPresenter;
use App\Service\SpaApi\Documents\DocumentCreateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/documents-flow')]
final class DocumentCreateController extends AbstractController
{
    public function __construct(
        private readonly DocumentCreateService $createService,
        private readonly DocumentApiPresenter $presenter,
    ) {
    }

    #[Route('/documents', name: 'spa_api_documents_flow_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        try {
            $document = $this->createService->create($payload, $user);
        } catch (HttpException $e) {
            $message = $e->getMessage();

            return $this->json(
                ['error' => $message !== '' ? $message : SpaApiError::DOCUMENT_VALIDATION_FAILED],
                $e->getStatusCode(),
            );
        }

        return $this->json([
            'document' => $this->presenter->presentDocumentListItem($document),
        ], Response::HTTP_CREATED);
    }
}
