<?php

namespace App\Controller\Kanban\Api;

use App\Repository\User\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/kanban/users')]
final class KanbanUserSearchApiController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepo,
    ) {
    }

    #[Route('/search', name: 'api_kanban_users_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = trim($request->query->getString('query', ''));

        $result = $this->userRepo->findPaginated(page: 1, limit: 20, search: $query);

        $data = [];
        foreach ($result['users'] as $u) {
            $data[] = ['id' => $u->getId(), 'name' => $u->getFirstname() . ' ' . $u->getLastname()];
        }

        return $this->json($data);
    }
}
