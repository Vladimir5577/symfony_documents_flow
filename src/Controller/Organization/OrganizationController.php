<?php

namespace App\Controller\Organization;

use App\Repository\OrganizationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class OrganizationController extends AbstractController
{
    #[Route('/view_organization/{id}', name: 'view_organization', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function viewOrganization(int $id, OrganizationRepository $organizationRepository): Response
    {
        $organization = $organizationRepository->createQueryBuilder('o')
            ->leftJoin('o.departments', 'd')
            ->addSelect('d')
            ->leftJoin('d.departmentDivisions', 'dd')
            ->addSelect('dd')
            ->where('o.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$organization) {
            throw $this->createNotFoundException('Организация не найдена');
        }

        return $this->render('organization/view_organization.html.twig', [
            'active_tab' => 'view_organization',
            'organization' => $organization,
        ]);
    }

    #[Route('/all_organizations', name: 'app_all_organizations', methods: ['GET'])]
    public function allOrganizations(Request $request, OrganizationRepository $organizationRepository): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 10; // Количество организаций на странице

        $pagination = $organizationRepository->findPaginated($page, $limit);

        return $this->render('organization/all_organizations.html.twig', [
            'active_tab' => 'all_organizations',
            'controller_name' => 'OrganizationController',
            'organizations' => $pagination['organizations'],
            'pagination' => [
                'current_page' => $pagination['page'],
                'total_pages' => $pagination['totalPages'],
                'total_items' => $pagination['total'],
                'items_per_page' => $pagination['limit'],
            ],
        ]);
    }

    #[Route('/create_organization', name: 'create_organization')]
    #[IsGranted('ROLE_ADMIN')]
    public function createOrganization(): Response
    {
        return $this->render('organization/create_organization.html.twig', [
            'active_tab' => 'create_organization',
        ]);
    }
}
