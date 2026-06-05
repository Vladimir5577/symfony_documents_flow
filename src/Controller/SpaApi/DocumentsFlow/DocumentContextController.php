<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Entity\User\User;
use App\Service\SpaApi\Documents\DocumentAccessService;
use App\Service\SpaApi\Documents\DocumentApiPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/documents-flow')]
final class DocumentContextController extends AbstractController
{
    public function __construct(
        private readonly DocumentAccessService $accessService,
        private readonly DocumentApiPresenter $presenter,
    ) {
    }

    #[Route('/context', name: 'spa_api_documents_flow_context', methods: ['GET'])]
    public function context(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->json([
            'isAdmin' => $this->accessService->isAdmin(),
            'organization' => $this->presenter->presentOrganizationBrief($user->getOrganization()),
        ]);
    }
}
