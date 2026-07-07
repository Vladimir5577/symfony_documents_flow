<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Kanban;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Kanban\Project\KanbanProjectUserFolder;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

// DISABLED KANBAN MODULE
// #[Route('/spa/api/project-folders')]
final class ProjectFolderController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'spa_api_project_folder_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => SpaApiError::FOLDER_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        if (mb_strlen($name) > 255) {
            return $this->json(['error' => SpaApiError::FOLDER_NAME_TOO_LONG], Response::HTTP_BAD_REQUEST);
        }

        // Calculate next position
        $maxPosition = (float) $this->entityManager->createQueryBuilder()
            ->select('MAX(f.position)')
            ->from(KanbanProjectUserFolder::class, 'f')
            ->where('f.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        $position = $maxPosition > 0 ? $maxPosition + 1.0 : 1.0;

        $folder = new KanbanProjectUserFolder();
        $folder->setName($name);
        $folder->setUser($user);
        $folder->setPosition($position);

        $this->entityManager->persist($folder);
        $this->entityManager->flush();

        return $this->json([
            'id' => $folder->getId(),
            'name' => $folder->getName(),
            'position' => $folder->getPosition(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spa_api_project_folder_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $folder = $this->entityManager->getRepository(KanbanProjectUserFolder::class)->find($id);
        if ($folder === null) {
            return $this->json(['error' => SpaApiError::FOLDER_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if ($folder->getUser() !== $user) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => SpaApiError::FOLDER_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        if (mb_strlen($name) > 255) {
            return $this->json(['error' => SpaApiError::FOLDER_NAME_TOO_LONG], Response::HTTP_BAD_REQUEST);
        }

        $folder->setName($name);
        $this->entityManager->flush();

        return $this->json([
            'id' => $folder->getId(),
            'name' => $folder->getName(),
            'position' => $folder->getPosition(),
        ]);
    }

    #[Route('/{id}', name: 'spa_api_project_folder_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $folder = $this->entityManager->getRepository(KanbanProjectUserFolder::class)->find($id);
        if ($folder === null) {
            return $this->json(['error' => SpaApiError::FOLDER_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if ($folder->getUser() !== $user) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        // Dissociate projects in this folder to avoid Doctrine UnitOfWork caching state issues
        foreach ($folder->getProjectUsers() as $projectUser) {
            $projectUser->setFolder(null);
        }

        $this->entityManager->remove($folder);
        $this->entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/move', name: 'spa_api_project_folder_move', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function move(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $folder = $this->entityManager->getRepository(KanbanProjectUserFolder::class)->find($id);
        if ($folder === null) {
            return $this->json(['error' => SpaApiError::FOLDER_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if ($folder->getUser() !== $user) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !isset($payload['position'])) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $position = (float) $payload['position'];
        $folder->setPosition($position);
        $this->entityManager->flush();

        $this->checkRebalance($user);

        return $this->json([
            'id' => $folder->getId(),
            'name' => $folder->getName(),
            'position' => $folder->getPosition(),
        ]);
    }

    private function checkRebalance(User $user): void
    {
        $folders = $this->entityManager->getRepository(KanbanProjectUserFolder::class)->findBy(
            ['user' => $user],
            ['position' => 'ASC']
        );

        $needsRebalance = false;
        for ($i = 1; $i < count($folders); $i++) {
            if (($folders[$i]->getPosition() - $folders[$i - 1]->getPosition()) < 0.0001) {
                $needsRebalance = true;
                break;
            }
        }

        if ($needsRebalance) {
            $pos = 1.0;
            foreach ($folders as $f) {
                $f->setPosition($pos);
                $pos += 1.0;
            }
            $this->entityManager->flush();
        }
    }
}
