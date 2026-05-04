<?php

namespace App\Controller\User;

use App\Repository\User\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class RoleController extends AbstractController
{
    #[Route('/roles', name: 'app_roles', methods: ['GET'])]
    public function index(RoleRepository $roleRepository): Response
    {
        $roles = $roleRepository->createQueryBuilder('r')
            ->orderBy('r.sortOrder', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('role/all_roles.html.twig', [
            'active_tab' => 'roles',
            'roles' => $roles,
        ]);
    }

    #[Route('/roles/reorder', name: 'app_roles_reorder', methods: ['POST'])]
    public function reorder(
        Request $request,
        RoleRepository $roleRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrf,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_payload'], 400);
        }

        $token = (string) ($payload['_token'] ?? '');
        if (!$csrf->isTokenValid(new CsrfToken('roles_reorder', $token))) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_csrf'], 400);
        }

        $order = $payload['order'] ?? null;
        if (!is_array($order)) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_order'], 400);
        }

        $em->wrapInTransaction(function () use ($order, $roleRepository): void {
            foreach ($order as $entry) {
                $id = (int) ($entry['id'] ?? 0);
                $position = (int) ($entry['position'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $role = $roleRepository->find($id);
                if ($role === null) {
                    continue;
                }
                $role->setSortOrder($position);
            }
        });

        return new JsonResponse(['ok' => true]);
    }
}
