<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\User;

use App\Entity\User\Role;
use App\Entity\User\User;
use App\Enum\User\WorkerStatus;
use App\Repository\Organization\OrganizationRepository;
use App\Repository\User\RoleRepository;
use App\Repository\User\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spa/api/users')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly OrganizationRepository $organizationRepository,
        private readonly RoleRepository $roleRepository,
    ) {
    }

    #[Route('/roles', name: 'spa_api_users_roles_list', methods: ['GET'])]
    public function rolesList(): JsonResponse
    {
        $roles = $this->roleRepository->findAllExceptAdmin();

        return $this->json([
            'roles' => array_map(
                static function (Role $role): array {
                    $label = $role->getLabel();
                    if ($label === null || $label === '') {
                        $label = $role->getRole()?->getLabel() ?? $role->getName();
                    }

                    return [
                        'id' => $role->getId(),
                        'name' => $role->getName(),
                        'label' => $label,
                        'sortOrder' => $role->getSortOrder(),
                    ];
                },
                $roles,
            ),
        ]);
    }

    #[Route('', name: 'spa_api_users_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $search = trim((string) $request->query->get('search', ''));
        $pageSize = max(1, min(100, (int) $request->query->get('page_size', 10)));
        $organizationId = $request->query->getInt('organization_id') ?: null;
        $status = $request->query->get('status');
        if ($status === '') {
            $status = null;
        }

        $orderBy = (string) $request->query->get('order_by', 'lastname');
        $allowedOrderBy = ['lastname', 'created_at', 'last_seen_at'];
        if (!\in_array($orderBy, $allowedOrderBy, true)) {
            $orderBy = 'lastname';
        }

        $order = strtoupper((string) $request->query->get('order', 'ASC'));
        $order = $order === 'DESC' ? 'DESC' : 'ASC';

        $organizationIds = null;
        if ($organizationId !== null && $organizationId > 0) {
            $organizationIds = $this->organizationRepository->findOrganizationWithChildrenIds($organizationId);
            if ($organizationIds === []) {
                return $this->json([
                    'users' => [],
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => 1,
                        'total_items' => 0,
                        'items_per_page' => $pageSize,
                    ],
                    'filters' => [
                        'statusChoices' => WorkerStatus::getChoices(),
                    ],
                ]);
            }
        }

        $pagination = $this->findPaginated($page, $pageSize, $search, $organizationIds, $status, $orderBy, $order);

        return $this->json([
            'users' => array_map(
                static function (User $user): array {
                    $worker = $user->getWorker();
                    $workerStatus = $worker?->getWorkerStatus();
                    $organization = $user->getOrganization();

                    return [
                        'id' => $user->getId(),
                        'lastname' => $user->getLastname() ?? '-',
                        'firstname' => $user->getFirstname() ?? '-',
                        'patronymic' => $user->getPatronymic() ?? '-',
                        'login' => $user->getLogin(),
                        'phone' => $user->getPhone() ?? '-',
                        'profession' => $worker?->getProfession() ?? '-',
                        'status' => $workerStatus?->value,
                        'statusLabel' => $workerStatus?->getLabel() ?? '-',
                        'organization' => $organization !== null ? [
                            'id' => $organization->getId(),
                            'name' => $organization->getName(),
                            'fullName' => $organization->getFullName(),
                        ] : null,
                        'lastSeenAt' => $user->getLastSeenAt()?->format(\DateTimeInterface::ATOM),
                        'createdAt' => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                    ];
                },
                $pagination['users'],
            ),
            'pagination' => [
                'current_page' => $pagination['page'],
                'total_pages' => $pagination['totalPages'],
                'total_items' => $pagination['total'],
                'items_per_page' => $pagination['limit'],
            ],
            'filters' => [
                'statusChoices' => WorkerStatus::getChoices(),
            ],
        ]);
    }

    /**
     * Пагинированный список для SPA: фильтр organization_id — по организации и всем дочерним.
     *
     * @param int[]|null $organizationIds ID организации и потомков (null = без фильтра по организации)
     * @return array{users: User[], total: int, page: int, limit: int, totalPages: int}
     */
    private function findPaginated(
        int $page,
        int $limit,
        string $search,
        ?array $organizationIds,
        ?string $status,
        string $orderBy,
        string $order,
    ): array {
        $offset = ($page - 1) * $limit;

        $orderField = match ($orderBy) {
            'created_at' => 'u.createdAt',
            'last_seen_at' => 'u.lastSeenAt',
            default => 'u.lastname',
        };

        $qb = $this->userRepository->createQueryBuilder('u');

        if (\in_array($orderBy, ['created_at', 'last_seen_at'], true)) {
            // null трактуем как самую раннюю дату: ASC — в начале, DESC — в конце
            $nullSort = $order === 'DESC'
                ? sprintf('CASE WHEN %s IS NULL THEN 1 ELSE 0 END', $orderField)
                : sprintf('CASE WHEN %s IS NULL THEN 0 ELSE 1 END', $orderField);

            $qb
                ->orderBy($nullSort, 'ASC')
                ->addOrderBy($orderField, $order);
        } else {
            $qb->orderBy($orderField, $order);
        }

        if ($orderBy === 'lastname') {
            $qb->addOrderBy('u.firstname', $order);
        }

        $countQb = $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)');

        if ($search !== '') {
            $searchCondition = 'LOWER(u.lastname) LIKE LOWER(:search) OR LOWER(u.firstname) LIKE LOWER(:search) OR LOWER(u.patronymic) LIKE LOWER(:search) OR LOWER(u.login) LIKE LOWER(:search) OR LOWER(u.phone) LIKE LOWER(:search)';
            $qb->andWhere($searchCondition)->setParameter('search', '%' . $search . '%');
            $countQb->andWhere($searchCondition)->setParameter('search', '%' . $search . '%');
        }

        if ($organizationIds !== null && $organizationIds !== []) {
            $qb->andWhere('u.organization IN (:orgIds)')->setParameter('orgIds', $organizationIds);
            $countQb->andWhere('u.organization IN (:orgIds)')->setParameter('orgIds', $organizationIds);
        }

        $statusEnum = $status !== null && $status !== '' ? WorkerStatus::tryFrom($status) : null;
        if ($statusEnum !== null) {
            $qb->leftJoin('u.worker', 'w')->andWhere('w.workerStatus = :status')->setParameter('status', $statusEnum);
            $countQb->leftJoin('u.worker', 'w')->andWhere('w.workerStatus = :status')->setParameter('status', $statusEnum);
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $users = $qb
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $totalPages = $total > 0 ? (int) ceil($total / $limit) : 1;

        return [
            'users' => $users,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $totalPages,
        ];
    }
}
