<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Organization\AbstractOrganization;
use App\Repository\Organization\OrganizationRepository;
use App\Repository\User\UserRepository;
use App\Service\Inventory\InventoryAccessResolver;
use App\Service\Inventory\InventoryUserPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Справочники для форм: куда можно класть товар и кого можно на него назначить.
 */
#[Route('/spa/api/inventory/references')]
#[IsGranted('ROLE_INVENTORY_MANAGER')]
final class ReferenceController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly UserRepository $userRepository,
        private readonly InventoryAccessResolver $accessResolver,
        private readonly InventoryUserPresenter $userPresenter,
    ) {
    }

    /**
     * Организации, доступные текущему пользователю, — уже вместе с потомками.
     */
    #[Route('/organizations', name: 'spa_api_inventory_reference_organizations', methods: ['GET'])]
    public function organizations(): JsonResponse
    {
        $scope = $this->accessResolver->resolveCurrent();

        $organizationIds = $scope->allOrganizationIds();
        if (!$scope->full && $organizationIds === []) {
            return $this->json(['organizations' => []]);
        }

        $organizations = $scope->full
            ? $this->organizationRepository->findAll()
            : $this->organizationRepository->findBy(['id' => $organizationIds]);

        usort(
            $organizations,
            static fn (AbstractOrganization $a, AbstractOrganization $b): int => strcmp($a->getName(), $b->getName()),
        );

        return $this->json([
            'organizations' => array_map(
                static fn (AbstractOrganization $organization): array => [
                    'id' => $organization->getId(),
                    'name' => $organization->getName(),
                    'fullName' => $organization->getFullName(),
                    'path' => $organization->getPath(),
                    'parentId' => $organization->getParent()?->getId(),
                ],
                $organizations,
            ),
        ]);
    }

    /**
     * Сотрудники ровно этой организации — без дочерних.
     *
     * Дерево в выборе владельца показывается целиком, и человек кликает по узлам,
     * чтобы понять, кто где числится. Если по клику на головную организацию сыпать
     * ещё и всех из филиалов, список превращается в кашу на тысячу строк, а понять,
     * к какому подразделению относится сотрудник, нельзя. Нужны люди из отдела —
     * кликните по отделу.
     *
     * Уволенные отсекаются глобальным фильтром soft-delete.
     */
    #[Route('/users', name: 'spa_api_inventory_reference_users', methods: ['GET'])]
    public function users(Request $request): JsonResponse
    {
        $organizationId = $request->query->getInt('organization_id');
        if ($organizationId <= 0) {
            return $this->json(['error' => SpaApiError::ORGANIZATION_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $scope = $this->accessResolver->resolveCurrent();
        if (!$scope->full && !in_array($organizationId, $scope->allOrganizationIds(), true)) {
            return $this->json(
                ['error' => SpaApiError::INVENTORY_ORGANIZATION_NOT_ALLOWED],
                Response::HTTP_FORBIDDEN,
            );
        }

        // Поля, а не сущности: у User есть worker — инверсная сторона OneToOne, прокси
        // на неё Doctrine построить не может и делает SELECT на каждого загруженного
        // человека. В отделе на 242 сотрудника это 242 лишних запроса, 370 мс против 8.
        $users = $this->userRepository->createQueryBuilder('u')
            ->select('u.id, u.lastname, u.firstname, u.patronymic, u.login')
            ->addSelect('IDENTITY(u.organization) AS organizationId')
            ->andWhere('u.organization = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->getQuery()
            ->getArrayResult();

        // Логин отдаётся только администратору инвентаризации: справочник
        // открыт всем менеджерам, а им для выбора человека хватает ФИО.
        $canSeeLogin = $this->userPresenter->canSeeLogin();

        return $this->json([
            'users' => array_map(
                static fn (array $user): array => [
                    'id' => $user['id'],
                    'lastname' => $user['lastname'] ?? '-',
                    'firstname' => $user['firstname'] ?? '-',
                    'patronymic' => $user['patronymic'] ?? '-',
                    'login' => $canSeeLogin ? $user['login'] : null,
                    'organizationId' => $user['organizationId'],
                ],
                $users,
            ),
        ]);
    }
}
