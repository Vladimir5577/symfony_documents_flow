<?php

namespace App\Controller\SpaApi;

use App\Entity\User\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class MeController extends AbstractController
{
    #[Route('/spa/api/me', name: 'spa_api_me', methods: ['GET'])]
    public function __invoke(#[CurrentUser] ?User $user): JsonResponse
    {
        return new JsonResponse([
            'id' => $user->getId(),
            'login' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
        ]);
    }
}
