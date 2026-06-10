<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\User;
use App\Repository\Organization\OrganizationRepository;
use App\Service\SpaApi\Documents\DocumentAccessService;
use App\Service\SpaApi\Documents\DocumentApiPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/documents-flow')]
final class DocumentOrganizationsController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly DocumentAccessService $accessService,
        private readonly DocumentApiPresenter $presenter,
    ) {
    }

    #[Route('/organizations/tree', name: 'spa_api_documents_flow_organizations_tree', methods: ['GET'])]
    public function tree(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $userOrganization = $user->getOrganization();
        $roots = $this->organizationRepository->getOrganizationTree(
            $this->accessService->isAdmin()
                ? null
                : ($userOrganization !== null ? $userOrganization->getRootOrganization() : null),
        );

        $organizations = [];
        foreach ($roots as $root) {
            $id = $root->getId();
            if ($id === null) {
                continue;
            }

            $loaded = $this->organizationRepository->findWithChildren($id);
            if ($loaded instanceof AbstractOrganization) {
                $organizations[] = $this->presenter->presentOrganizationTreeNode($loaded);
            }
        }

        return $this->json(['organizations' => $organizations]);
    }
}
