<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\ItemCategory;
use App\Entity\Inventory\Nomenclature;
use App\Repository\Inventory\ItemCategoryRepository;
use App\Repository\Inventory\NomenclatureItemRepository;
use App\Repository\Inventory\NomenclatureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Справочник видов товара, общий на всю компанию.
 *
 * Читают все админы инвентаризации — вид обязателен при заведении позиции, и без
 * списка выбрать было бы не из чего. Правит только главный администратор: именно
 * из-за свободного ввода наименований справочник и понадобился.
 */
#[Route('/spa/api/inventory/nomenclature')]
#[IsGranted('ROLE_INVENTORY_MANAGER')]
final class NomenclatureController extends AbstractController
{
    public function __construct(
        private readonly NomenclatureRepository $nomenclatureRepository,
        private readonly ItemCategoryRepository $categoryRepository,
        private readonly NomenclatureItemRepository $itemRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * У ручки два вызывающих, и они хотят разного.
     *
     * Экран справочника просит всё и со счётчиками. Пикер в форме позиции шлёт
     * `search` и `limit` и спрашивает заново на каждый набранный символ — ему нужны
     * несколько совпадений, а весь справочник на сотни строк он и показать не сможет.
     *
     * Счётчики считаются только главному администратору: экран его, а пикеру числа
     * не нужны. Заодно это не отдаёт количества по всей компании тому, кто ведёт
     * одну организацию.
     */
    #[Route('', name: 'spa_api_inventory_nomenclature_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $search = trim((string) $request->query->get('search', ''));

        // Потолок обязателен, когда его просят: без него подобранный limit=100000
        // вернул бы весь справочник в обход пагинации, которой тут нет.
        $limit = $request->query->has('limit')
            ? max(1, min(100, $request->query->getInt('limit')))
            : null;

        $counts = $this->isGranted('ROLE_INVENTORY_ADMIN')
            ? $this->itemRepository->countGroupedByNomenclature()
            : [];

        return $this->json([
            'nomenclature' => array_map(
                fn (Nomenclature $nomenclature): array => $this->format(
                    $nomenclature,
                    $counts[(int) $nomenclature->getId()] ?? null,
                ),
                $this->nomenclatureRepository->findAllOrdered($search, $limit),
            ),
        ]);
    }

    #[Route('', name: 'spa_api_inventory_nomenclature_create', methods: ['POST'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->json(
                ['error' => SpaApiError::INVENTORY_NOMENCLATURE_NAME_REQUIRED],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $existing = $this->nomenclatureRepository->findOneByName($name);
        if ($existing !== null) {
            // Отдаём найденный вид: человек хотел завести «Монитор DELL», а такой
            // уже есть как «Монитор Dell» — пусть выберет его, а не гадает.
            return $this->json([
                'error' => SpaApiError::INVENTORY_NOMENCLATURE_NAME_EXISTS,
                'nomenclature' => $this->format($existing),
            ], Response::HTTP_CONFLICT);
        }

        [$category, $categoryError] = $this->resolveCategory($payload['categoryId'] ?? null);
        if ($categoryError !== null) {
            return $categoryError;
        }

        $nomenclature = new Nomenclature();
        $nomenclature->setName(mb_substr($name, 0, 255));
        $nomenclature->setCategory($category);

        $this->em->persist($nomenclature);
        $this->em->flush();

        return $this->json(['nomenclature' => $this->format($nomenclature)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spa_api_inventory_nomenclature_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $nomenclature = $this->nomenclatureRepository->find($id);
        if (!$nomenclature instanceof Nomenclature) {
            return $this->json(
                ['error' => SpaApiError::INVENTORY_NOMENCLATURE_NOT_FOUND],
                Response::HTTP_NOT_FOUND,
            );
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                return $this->json(
                    ['error' => SpaApiError::INVENTORY_NOMENCLATURE_NAME_REQUIRED],
                    Response::HTTP_BAD_REQUEST,
                );
            }

            $duplicate = $this->nomenclatureRepository->findOneByName($name, $nomenclature->getId());
            if ($duplicate !== null) {
                return $this->json([
                    'error' => SpaApiError::INVENTORY_NOMENCLATURE_NAME_EXISTS,
                    'nomenclature' => $this->format($duplicate),
                ], Response::HTTP_CONFLICT);
            }

            $nomenclature->setName(mb_substr($name, 0, 255));
        }

        // Смена категории вида не пишет историю позициям: их могут быть тысячи,
        // и лента каждой заполнилась бы событием, которого никто не совершал.
        if (array_key_exists('categoryId', $payload)) {
            [$category, $categoryError] = $this->resolveCategory($payload['categoryId']);
            if ($categoryError !== null) {
                return $categoryError;
            }

            $nomenclature->setCategory($category);
        }

        $this->em->flush();

        return $this->json(['nomenclature' => $this->format($nomenclature)]);
    }

    #[Route('/{id}', name: 'spa_api_inventory_nomenclature_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[IsGranted('ROLE_INVENTORY_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $nomenclature = $this->nomenclatureRepository->find($id);
        if (!$nomenclature instanceof Nomenclature) {
            return $this->json(
                ['error' => SpaApiError::INVENTORY_NOMENCLATURE_NOT_FOUND],
                Response::HTTP_NOT_FOUND,
            );
        }

        $itemsCount = $this->itemRepository->countByNomenclature((int) $nomenclature->getId());
        if ($itemsCount > 0) {
            return $this->json([
                'error' => SpaApiError::INVENTORY_NOMENCLATURE_HAS_ITEMS,
                'itemsCount' => $itemsCount,
            ], Response::HTTP_CONFLICT);
        }

        $this->em->remove($nomenclature);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    /**
     * Категория вида. Необязательна: неразобранный вид заводится без неё, как раньше
     * заводился без категории отдельный предмет. Неактивную ставить нельзя — она
     * не предлагается в формах, и вид с ней стал бы невыбираемым.
     *
     * @return array{0: ItemCategory|null, 1: JsonResponse|null}
     */
    private function resolveCategory(mixed $rawId): array
    {
        $categoryId = (int) ($rawId ?? 0);
        if ($categoryId <= 0) {
            return [null, null];
        }

        $category = $this->categoryRepository->find($categoryId);
        if (!$category instanceof ItemCategory) {
            return [null, $this->json(
                ['error' => SpaApiError::INVENTORY_CATEGORY_NOT_FOUND],
                Response::HTTP_NOT_FOUND,
            )];
        }

        if (!$category->isActive()) {
            return [null, $this->json(
                ['error' => SpaApiError::INVENTORY_CATEGORY_NOT_ALLOWED],
                Response::HTTP_CONFLICT,
            )];
        }

        return [$category, null];
    }

    /**
     * @param array{total: int, assigned: int}|null $counts null — вид без единой позиции
     *                                                      либо счётчики не запрашивались
     */
    private function format(Nomenclature $nomenclature, ?array $counts = null): array
    {
        $category = $nomenclature->getCategory();

        $formatted = [
            'id' => $nomenclature->getId(),
            'name' => $nomenclature->getName(),
            'category' => $category !== null
                ? ['id' => $category->getId(), 'name' => $category->getName()]
                : null,
        ];

        // Нули для вида без позиций приходят отсюда, а не из группировки: строк с
        // нулём в ней просто нет, а на экране колонка не должна пустовать.
        if ($this->isGranted('ROLE_INVENTORY_ADMIN')) {
            $formatted['total'] = $counts['total'] ?? 0;
            $formatted['assigned'] = $counts['assigned'] ?? 0;
        }

        return $formatted;
    }
}
