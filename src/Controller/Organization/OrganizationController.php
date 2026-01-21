<?php

namespace App\Controller\Organization;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OrganizationController extends AbstractController
{
    #[Route('/all_organizations', name: 'app_all_organizations')]
    public function allOrganizations(): Response
    {
        return $this->render('organization/all_organizations.html.twig', [
            'active_tab' => 'all_organizations',
        ]);
    }

    #[Route('/create_organization', name: 'create_organization')]
    public function createOrganization(): Response
    {
        return $this->render('organization/create_organization.html.twig', [
            'active_tab' => 'create_organization',
        ]);
    }
}
