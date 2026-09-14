<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Acknowledgment;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Acknowledgment\AckCategory;
use App\Entity\User\User;
use App\Repository\Acknowledgment\AckCategoryRepository;
use App\Service\Acknowledgment\AckPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Категории раздела. Читают все — это навигация по «моим документам», поиска в
 * модуле нет. Ведёт делопроизводство.
 */
#[Route('/spa/api/acknowledgment/categories')]
final class AckCategoryController extends AbstractController
{
    public function __construct(
        private readonly AckCategoryRepository $categoryRepo,
        private readonly AckPresenter $presenter,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'spa_api_ack_categories_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->json([
            'items' => array_map(
                fn (AckCategory $category): array => $this->presenter->presentCategory($category),
                $this->categoryRepo->findAllOrdered(),
            ),
        ]);
    }

    #[Route('', name: 'spa_api_ack_categories_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_DOC_OFFICE')) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $name = $this->extractName($payload);
        if ($name === null) {
            return $this->json(['error' => SpaApiError::ACK_CATEGORY_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        if ($this->categoryRepo->findOneBy(['name' => $name]) !== null) {
            return $this->json(['error' => SpaApiError::ACK_CATEGORY_NAME_TAKEN], Response::HTTP_CONFLICT);
        }

        $category = (new AckCategory())
            ->setName($name)
            ->setSort((int) ($payload['sort'] ?? 0));

        $this->em->persist($category);
        $this->em->flush();

        return $this->json($this->presenter->presentCategory($category), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spa_api_ack_categories_update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_DOC_OFFICE')) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $category = $this->categoryRepo->find($id);
        if ($category === null) {
            return $this->json(['error' => SpaApiError::ACK_CATEGORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $name = $this->extractName($payload);
        if ($name === null) {
            return $this->json(['error' => SpaApiError::ACK_CATEGORY_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $existing = $this->categoryRepo->findOneBy(['name' => $name]);
        if ($existing !== null && $existing->getId() !== $category->getId()) {
            return $this->json(['error' => SpaApiError::ACK_CATEGORY_NAME_TAKEN], Response::HTTP_CONFLICT);
        }

        $category->setName($name);
        if (array_key_exists('sort', $payload)) {
            $category->setSort((int) $payload['sort']);
        }

        $this->em->flush();

        return $this->json($this->presenter->presentCategory($category));
    }

    #[Route('/{id}', name: 'spa_api_ack_categories_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        if (!$this->isGranted('ROLE_DOC_OFFICE')) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $category = $this->categoryRepo->find($id);
        if ($category === null) {
            return $this->json(['error' => SpaApiError::ACK_CATEGORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        // Категория у документа обязательна, а связь стоит на RESTRICT: без этой
        // проверки удаление уронило бы запрос на уровне базы.
        if ($this->categoryRepo->hasDocuments($category)) {
            return $this->json(['error' => SpaApiError::ACK_CATEGORY_HAS_DOCUMENTS], Response::HTTP_CONFLICT);
        }

        $this->em->remove($category);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractName(array $payload): ?string
    {
        $name = is_string($payload['name'] ?? null) ? trim($payload['name']) : '';

        return $name !== '' ? mb_substr($name, 0, 255) : null;
    }
}
