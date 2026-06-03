<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Service\SpaApi\Documents\DocumentApiPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/documents-flow')]
final class DocumentUsersController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly DocumentApiPresenter $presenter,
    ) {
    }

    #[Route('/organizations/{id}/users', name: 'spa_api_documents_flow_org_users', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function organizationUsers(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $organization = $this->entityManager->find(AbstractOrganization::class, $id);
        if ($organization === null) {
            return $this->json(['error' => SpaApiError::ORGANIZATION_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $users = $this->userRepository->findByOrganization($organization);

        return $this->json([
            'users' => array_map(
                fn (User $u) => $this->presenter->presentUserBrief($u),
                $users,
            ),
        ]);
    }

    #[Route('/users/search', name: 'spa_api_documents_flow_users_search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $query = trim((string) $request->query->get('query', ''));
        if (mb_strlen($query) < 2) {
            return $this->json(['users' => []]);
        }

        $result = $this->userRepository->findPaginated(1, 20, $query);

        return $this->json([
            'users' => array_map(
                fn (User $u) => $this->presenter->presentUserBrief($u),
                $result['users'],
            ),
        ]);
    }
}
