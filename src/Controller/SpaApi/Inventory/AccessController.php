<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\InventoryAccess;
use App\Entity\Inventory\ItemCategory;
use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\Role;
use App\Entity\User\User;
use App\Enum\User\UserRole;
use App\Repository\Inventory\InventoryAccessRepository;
use App\Repository\Inventory\ItemCategoryRepository;
use App\Repository\Organization\OrganizationRepository;
use App\Repository\User\RoleRepository;
use App\Repository\User\UserRepository;
use App\Service\Inventory\InventoryAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Привязки пользователей к организациям и категориям.
 *
 * Выдавать и отзывать может главный администратор (в любой организации) и админ
 * инвентаризации организации (в своём поддереве).
 */
#[Route('/spa/api/inventory/access')]
#[IsGranted('ROLE_INVENTORY_MANAGER')]
final class AccessController extends AbstractController
{
    public function __construct(
        private readonly InventoryAccessRepository $accessRepository,
        private readonly InventoryAccessResolver $accessResolver,
        private readonly ItemCategoryRepository $categoryRepository,
        private readonly OrganizationRepository $organizationRepository,
        private readonly UserRepository $userRepository,
        private readonly RoleRepository $roleRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'spa_api_inventory_access_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $scope = $this->accessResolver->resolveCurrent();

        $rows = $scope->full
            ? $this->accessRepository->findAllWithRelations()
            : $this->accessRepository->findByOrganizationIds($scope->adminOrgIds);

        return $this->json([
            'access' => array_map(
                fn (InventoryAccess $access): array => $this->format($access),
                $rows,
            ),
            // Ответственный за категорию проходит сюда по роли, но управлять доступами
            // не может: список у него всегда пуст, а выдача вернёт 403. Роль одна на
            // обе зоны ответственности, отличить их фронт сам не может — поэтому
            // отдаём готовый ответ, чтобы он не рисовал кнопку, обречённую на отказ.
            'canManage' => $scope->full || $scope->adminOrgIds !== [],
        ]);
    }

    #[Route('', name: 'spa_api_inventory_access_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $organizationId = (int) ($payload['organizationId'] ?? 0);
        if (!$this->accessResolver->canManageAccessIn($organizationId)) {
            return $this->json(
                ['error' => SpaApiError::INVENTORY_ORGANIZATION_NOT_ALLOWED],
                Response::HTTP_FORBIDDEN,
            );
        }

        $organization = $this->organizationRepository->find($organizationId);
        if (!$organization instanceof AbstractOrganization) {
            return $this->json(['error' => SpaApiError::ORGANIZATION_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $user = $this->userRepository->find((int) ($payload['userId'] ?? 0));
        if (!$user instanceof User) {
            return $this->json(['error' => SpaApiError::USER_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $category = null;
        $categoryId = isset($payload['categoryId']) ? (int) $payload['categoryId'] : null;
        if ($categoryId !== null && $categoryId > 0) {
            $category = $this->categoryRepository->find($categoryId);
            if (!$category instanceof ItemCategory) {
                return $this->json(['error' => SpaApiError::INVENTORY_CATEGORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
            }
        }

        if ($this->accessResolver->findExisting($user, $organizationId, $category?->getId()) !== null) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_EXISTS], Response::HTTP_CONFLICT);
        }

        $access = new InventoryAccess();
        $access->setUser($user);
        $access->setOrganization($organization);
        $access->setCategory($category);
        $access->setCreatedBy($currentUser);

        $this->em->persist($access);
        $this->grantInventoryRole($user);
        $this->em->flush();

        return $this->json(['access' => $this->format($access)], Response::HTTP_CREATED);
    }

    /**
     * Меняется только зона ответственности: категория или «вся организация».
     *
     * Сотрудника и организацию через правку не переносим — это не изменение одной
     * привязки, а отзыв доступа у одного человека и выдача другому; такие решения
     * принимаются отдельно и делаются двумя операциями.
     */
    #[Route('/{id}', name: 'spa_api_inventory_access_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $access = $this->accessRepository->find($id);
        if (!$access instanceof InventoryAccess) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $organizationId = (int) $access->getOrganization()->getId();
        if (!$this->accessResolver->canManageAccessIn($organizationId)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        // categoryId = null или 0 — сделать администратором всей организации.
        $category = null;
        $categoryId = (int) ($payload['categoryId'] ?? 0);
        if ($categoryId > 0) {
            $category = $this->categoryRepository->find($categoryId);
            if (!$category instanceof ItemCategory) {
                return $this->json(['error' => SpaApiError::INVENTORY_CATEGORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
            }
        }

        if ($access->getCategory()?->getId() === $category?->getId()) {
            return $this->json(['access' => $this->format($access)]);
        }

        $duplicate = $this->accessResolver->findExisting(
            $access->getUser(),
            $organizationId,
            $category?->getId(),
        );
        if ($duplicate !== null) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_EXISTS], Response::HTTP_CONFLICT);
        }

        $access->setCategory($category);
        $this->em->flush();

        return $this->json(['access' => $this->format($access)]);
    }

    #[Route('/{id}', name: 'spa_api_inventory_access_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $access = $this->accessRepository->find($id);
        if (!$access instanceof InventoryAccess) {
            return $this->json(['error' => SpaApiError::INVENTORY_ACCESS_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->accessResolver->canManageAccessIn((int) $access->getOrganization()->getId())) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $user = $access->getUser();

        $this->em->remove($access);
        $this->em->flush();

        if ($this->accessRepository->count(['user' => $user]) === 0) {
            $this->revokeInventoryRole($user);
            $this->em->flush();
        }

        return $this->json(['success' => true]);
    }

    /**
     * Роль — кэш привязок для гейта контроллеров и бокового меню, а не источник прав.
     *
     * Выводить её на лету из inventory_access нельзя: User::getRoles() вызывается на
     * каждом запросе stateless-файрвола, и модуль добавил бы лишний SELECT всему
     * приложению. Поэтому роль хранится, а сопровождается ровно здесь — при выдаче
     * и отзыве привязки. Настоящие права всё равно считает InventoryScope: устаревшая
     * роль пускает максимум на пустой экран, доступа к чужим товарам она не даёт.
     */
    private function grantInventoryRole(User $user): void
    {
        $role = $this->roleRepository->findOneBy(['name' => UserRole::ROLE_INVENTORY_MANAGER->value]);

        if (!$role instanceof Role) {
            // Строки роли ещё нет (не запускали app:roles:sync) — создаём здесь же.
            $role = new Role(UserRole::ROLE_INVENTORY_MANAGER);
            $role->setLabel(UserRole::ROLE_INVENTORY_MANAGER->getLabel());
            $this->em->persist($role);
        }

        $user->addRoleEntity($role);
    }

    private function revokeInventoryRole(User $user): void
    {
        foreach ($user->getRolesRel() as $userRole) {
            if ($userRole->getRole()?->getName() === UserRole::ROLE_INVENTORY_MANAGER->value) {
                $user->getRolesRel()->removeElement($userRole);
            }
        }
    }

    private function format(InventoryAccess $access): array
    {
        $user = $access->getUser();
        $organization = $access->getOrganization();
        $category = $access->getCategory();
        $createdBy = $access->getCreatedBy();

        return [
            'id' => $access->getId(),
            'user' => [
                'id' => $user->getId(),
                'lastname' => $user->getLastname() ?? '-',
                'firstname' => $user->getFirstname() ?? '-',
                'patronymic' => $user->getPatronymic() ?? '-',
                'login' => $user->getLogin(),
            ],
            'organization' => [
                'id' => $organization->getId(),
                'name' => $organization->getName(),
                'fullName' => $organization->getFullName(),
                'path' => $organization->getPath(),
            ],
            'category' => $category !== null
                ? ['id' => $category->getId(), 'name' => $category->getName()]
                : null,
            'isOrganizationAdmin' => $access->isOrganizationAdmin(),
            'createdBy' => $createdBy !== null
                ? ['id' => $createdBy->getId(), 'login' => $createdBy->getLogin()]
                : null,
            'createdAt' => $access->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
