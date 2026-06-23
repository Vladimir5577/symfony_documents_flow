<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Entity\User\User;
use App\Repository\Organization\OrganizationRepository;
use App\Service\SpaApi\Documents\DocumentApiPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/documents-flow')]
final class DocumentOrganizationTreeController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly DocumentApiPresenter $presenter,
    ) {
    }

    /**
     * Полное дерево организаций для выбора получателей/исполнителей.
     * Логика совпадает с web-формой создания документа (DocumentController::createDocument).
     */
    #[Route('/organizations/tree', name: 'spa_api_documents_flow_organizations_tree', methods: ['GET'])]
    public function tree(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $userOrganization = $user->getOrganization();
        $organizationTree = $this->organizationRepository->getOrganizationTree(null);

        $organizationsWithChildren = [];
        foreach ($organizationTree as $org) {
            $loadedOrg = $this->organizationRepository->findWithChildren($org->getId());
            if ($loadedOrg !== null) {
                $organizationsWithChildren[] = $loadedOrg;
            }
        }

        if ($organizationsWithChildren === [] && $userOrganization !== null) {
            $loadedOrg = $this->organizationRepository->findWithChildren($userOrganization->getId());
            if ($loadedOrg !== null) {
                $organizationsWithChildren[] = $loadedOrg;
            }
        }

        return $this->json([
            'organizations' => array_map(
                fn ($organization) => $this->presenter->presentOrganizationTreeNode($organization),
                $organizationsWithChildren,
            ),
        ]);
    }
}
