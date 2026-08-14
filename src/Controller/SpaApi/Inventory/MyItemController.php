<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Entity\Inventory\NomenclatureItem;
use App\Entity\User\User;
use App\Repository\Inventory\NomenclatureItemRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Товары, закреплённые за текущим пользователем.
 *
 * Роль инвентаризации здесь намеренно не требуется: свои вещи видит любой сотрудник,
 * поэтому эндпоинт вынесен из ItemController, закрытого ROLE_INVENTORY_MANAGER.
 */
#[Route('/spa/api/inventory/my-items')]
final class MyItemController extends AbstractController
{
    public function __construct(
        private readonly NomenclatureItemRepository $itemRepository,
    ) {
    }

    /**
     * Постранично и с поиском: на подотчёте у кладовщика бывают тысячи позиций.
     * Параметры и форма блока pagination — те же, что в общем списке товаров.
     */
    #[Route('', name: 'spa_api_inventory_my_items_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $page = max(1, $request->query->getInt('page', 1));
        $pageSize = max(1, min(100, $request->query->getInt('page_size', 20)));

        $pagination = $this->itemRepository->findAssignedTo(
            $user,
            (string) $request->query->get('search', ''),
            $page,
            $pageSize,
        );

        return $this->json([
            'items' => array_map(
                fn (NomenclatureItem $item): array => $this->format($item),
                $pagination['items'],
            ),
            'pagination' => [
                'current_page' => $pagination['page'],
                'total_pages' => $pagination['totalPages'],
                'total_items' => $pagination['total'],
                'items_per_page' => $pagination['limit'],
            ],
        ]);
    }

    private function format(NomenclatureItem $item): array
    {
        $nomenclature = $item->getNomenclature();
        $organization = $item->getOrganization();
        $category = $item->getCategory();

        return [
            'id' => $item->getId(),
            'nomenclature' => [
                'id' => $nomenclature->getId(),
                'name' => $nomenclature->getName(),
            ],
            'inventoryNumber' => $item->getInventoryNumber(),
            'serialNumber' => $item->getSerialNumber(),
            // Категория приходит с вида, своего поля у позиции нет.
            'category' => $category !== null
                ? ['id' => $category->getId(), 'name' => $category->getName()]
                : null,
            'organization' => $organization !== null
                ? [
                    'id' => $organization->getId(),
                    'name' => $organization->getName(),
                    'path' => $organization->getPath(),
                ]
                : null,
            'status' => $item->getStatus()->value,
            'statusLabel' => $item->getStatus()->getLabel(),
            'description' => $item->getDescription(),
        ];
    }
}
