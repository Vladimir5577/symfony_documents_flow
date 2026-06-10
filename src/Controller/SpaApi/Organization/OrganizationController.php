<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Organization;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Organization\AbstractOrganization;
use App\Entity\Organization\Department;
use App\Entity\Organization\Filial;
use App\Enum\Organization\OrganizationType;
use App\Repository\Organization\OrganizationRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spa/api/organizations')]
final class OrganizationController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'spa_api_organizations_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $search = trim((string) $request->query->get('search', ''));
        $pageSize = max(1, min(100, (int) $request->query->get('page_size', 10)));

        $pagination = $this->organizationRepository->findPaginated($page, $pageSize, $search);

        return $this->json([
            'organizations' => array_map(
                fn (AbstractOrganization $organization) => [
                    'id' => $organization->getId(),
                    'name' => $organization->getName(),
                    'fullName' => $organization->getFullName(),
                    'legalAddress' => $organization->getLegalAddress() ?? '-',
                    'actualAddress' => $organization->getActualAddress() ?? '-',
                    'phone' => $organization->getPhone() ?? '-',
                    'email' => $organization->getEmail() ?? '-',
                ],
                $pagination['organizations'],
            ),
            'pagination' => [
                'current_page' => $pagination['page'],
                'total_pages' => $pagination['totalPages'],
                'total_items' => $pagination['total'],
                'items_per_page' => $pagination['limit'],
            ],
        ]);
    }

    #[Route('/{id}', name: 'spa_api_organization_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function view(int $id): JsonResponse
    {
        $organization = $this->em->getRepository(AbstractOrganization::class)
            ->createQueryBuilder('o')
            ->leftJoin('o.childOrganizations', 'co')->addSelect('co')
            ->leftJoin('co.childOrganizations', 'co2')->addSelect('co2')
            ->leftJoin('co2.childOrganizations', 'co3')->addSelect('co3')
            ->where('o.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$organization instanceof AbstractOrganization) {
            return $this->json(['error' => SpaApiError::ORGANIZATION_NOT_FOUND], 404);
        }

        $organizationType = $organization instanceof Filial
            ? OrganizationType::FILIAL
            : ($organization instanceof Department ? OrganizationType::DEPARTMENT : OrganizationType::ORGANIZATION);

        $users = $this->userRepository->findByOrganization($organization);

        $serializeOrg = static fn (AbstractOrganization $org): array => [
            'id' => $org->getId(),
            'name' => $org->getName(),
            'fullName' => $org->getFullName(),
        ];

        $parent = $organization->getParent();

        $regDate = $organization->getRegistrationDate();
        $taxType = $organization->getTaxType();

        return $this->json([
            'organization' => [
                'id' => $organization->getId(),
                'type' => $organizationType->value,
                'typeLabel' => $organizationType->getLabel(),
                'name' => $organization->getName(),
                'fullName' => $organization->getFullName(),
                'description' => $organization->getDescription(),
                'legalAddress' => $organization->getLegalAddress(),
                'actualAddress' => $organization->getActualAddress(),
                'phone' => $organization->getPhone(),
                'email' => $organization->getEmail(),
                'inn' => $organization->getInn(),
                'kpp' => $organization->getKpp(),
                'ogrn' => $organization->getOgrn(),
                'registrationDate' => $regDate?->format('Y-m-d'),
                'registrationOrgan' => $organization->getRegistrationOrgan(),
                'bankName' => $organization->getBankName(),
                'bik' => $organization->getBik(),
                'bankAccount' => $organization->getBankAccount(),
                'taxType' => $taxType?->value,
                'taxTypeLabel' => $taxType?->getLabel(),
                'createdAt' => $organization->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'updatedAt' => $organization->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
                'parent' => $parent !== null ? $serializeOrg($parent) : null,
                'childOrganizations' => $organization->getChildOrganizations()->map(
                    static fn (AbstractOrganization $child): array => [
                        ...$serializeOrg($child),
                        'childOrganizations' => $child->getChildOrganizations()->map(
                            static fn (AbstractOrganization $grandchild): array => [
                                ...$serializeOrg($grandchild),
                                'childOrganizations' => $grandchild->getChildOrganizations()
                                    ->map($serializeOrg)
                                    ->toArray(),
                            ]
                        )->toArray(),
                    ]
                )->toArray(),
            ],
            'users' => array_map(
                static function ($user) {
                    $worker = $user->getWorker();
                    $workerStatus = $worker?->getWorkerStatus();

                    return [
                        'id' => $user->getId(),
                        'fullName' => trim(implode(' ', array_filter([
                            $user->getLastname(),
                            $user->getFirstname(),
                            $user->getPatronymic(),
                        ]))),
                        'profession' => $worker?->getProfession(),
                        'status' => $workerStatus?->value,
                        'statusLabel' => $workerStatus?->getLabel(),
                    ];
                },
                $users,
            ),
        ]);
    }
}
