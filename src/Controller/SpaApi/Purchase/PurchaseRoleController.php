<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Entity\User\User;
use App\Enum\Purchase\PurchaseCapability;
use App\Enum\Purchase\PurchaseRoleCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Роли модуля закупок: кто бывает в маршруте и что кому можно вне него.
 *
 * Только чтение. Состав ролей и их полномочия живут в PurchaseRoleCode, потому
 * что это регламент, а не данные: он меняется вместе с кодом модуля. Админке
 * этот список нужен, чтобы рисовать галочки на экране участников, — оттуда роли
 * и выдаются людям.
 *
 * Читать может любой авторизованный: названия ролей стоят на шагах маршрута и
 * видны всем участникам, а полномочия здесь — пояснение к галочке, не секрет.
 */
#[Route('/spa/api/purchase-roles')]
final class PurchaseRoleController extends AbstractController
{
    #[Route('', name: 'spa_api_purchase_roles_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->json([
            'items' => array_map(
                static fn (PurchaseRoleCode $code): array => [
                    'code' => $code->value,
                    'name' => $code->getLabel(),
                    'capabilities' => array_map(
                        static fn (PurchaseCapability $capability): string => $capability->value,
                        $code->getCapabilities(),
                    ),
                ],
                PurchaseRoleCode::cases(),
            ),
            'capabilities' => array_map(
                static fn (PurchaseCapability $capability): array => [
                    'value' => $capability->value,
                    'label' => $capability->getLabel(),
                ],
                PurchaseCapability::cases(),
            ),
        ]);
    }
}
