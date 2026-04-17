<?php

declare(strict_types=1);

namespace App\Controller\Analytics;

use App\Entity\Organization\AbstractOrganization;
use App\Service\Analytics\AnalyticsOrganizationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AnalyticsAdminOrganizationController extends AbstractController
{
    #[Route('/analytics/admin/organization', name: 'app_analytics_admin_organization_index')]
    public function index(AnalyticsOrganizationService $service): Response
    {
        $organizations = $service->findAll();

        return $this->render('analytics/admin/organization/index.html.twig', [
            'organizations' => $organizations,
            'active_tab' => 'analytics_organizations',
        ]);
    }

    #[Route('/analytics/admin/organization/new', name: 'app_analytics_admin_organization_new')]
    public function new(
        Request $request,
        CsrfTokenManagerInterface $csrf,
        AnalyticsOrganizationService $service,
        EntityManagerInterface $em,
    ): Response {
        if ($request->isMethod('POST')) {
            if (!$csrf->isTokenValid(new CsrfToken('organization_new', $request->request->getString('_token')))) {
                $this->addFlash('error', 'Неверный CSRF-токен.');
                return $this->redirectToRoute('app_analytics_admin_organization_new');
            }

            $orgId = $request->request->getInt('organization_id');
            $organization = $em->getRepository(AbstractOrganization::class)->find($orgId);

            if (!$organization) {
                $this->addFlash('error', 'Организация не найдена.');
                return $this->redirectToRoute('app_analytics_admin_organization_new');
            }

            try {
                $service->create(
                    organization: $organization,
                    sortOrder: $request->request->getInt('sort_order'),
                    isVisible: (bool) $request->request->get('is_visible', false),
                );
                $this->addFlash('success', 'Организация добавлена в аналитику.');
                return $this->redirectToRoute('app_analytics_admin_organization_index');
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        $allOrganizations = $em->getRepository(AbstractOrganization::class)->findAll();

        return $this->render('analytics/admin/organization/new.html.twig', [
            'allOrganizations' => $allOrganizations,
            'active_tab' => 'analytics_organizations',
        ]);
    }

    #[Route('/analytics/admin/organization/{id}/edit', name: 'app_analytics_admin_organization_edit')]
    public function edit(
        int $id,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        AnalyticsOrganizationService $service,
        EntityManagerInterface $em,
    ): Response {
        $analyticsOrg = $service->findById($id);
        if (!$analyticsOrg) {
            throw $this->createNotFoundException('Организация не найдена.');
        }

        if ($request->isMethod('POST')) {
            if (!$csrf->isTokenValid(new CsrfToken('organization_edit_' . $id, $request->request->getString('_token')))) {
                $this->addFlash('error', 'Неверный CSRF-токен.');
                return $this->redirectToRoute('app_analytics_admin_organization_edit', ['id' => $id]);
            }

            $orgId = $request->request->getInt('organization_id');
            $organization = $em->getRepository(AbstractOrganization::class)->find($orgId);

            if (!$organization) {
                $this->addFlash('error', 'Организация не найдена.');
                return $this->redirectToRoute('app_analytics_admin_organization_edit', ['id' => $id]);
            }

            try {
                $service->update(
                    analyticsOrg: $analyticsOrg,
                    organization: $organization,
                    sortOrder: $request->request->getInt('sort_order'),
                    isVisible: (bool) $request->request->get('is_visible', false),
                );
                $this->addFlash('success', 'Организация обновлена.');
                return $this->redirectToRoute('app_analytics_admin_organization_index');
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        $allOrganizations = $em->getRepository(AbstractOrganization::class)->findAll();

        return $this->render('analytics/admin/organization/edit.html.twig', [
            'analyticsOrg' => $analyticsOrg,
            'allOrganizations' => $allOrganizations,
            'active_tab' => 'analytics_organizations',
        ]);
    }

    #[Route('/analytics/admin/organization/{id}/delete', name: 'app_analytics_admin_organization_delete', methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        AnalyticsOrganizationService $service,
    ): Response {
        $analyticsOrg = $service->findById($id);
        if (!$analyticsOrg) {
            throw $this->createNotFoundException('Организация не найдена.');
        }

        if (!$csrf->isTokenValid(new CsrfToken('organization_delete_' . $id, $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_admin_organization_index');
        }

        try {
            $service->delete($analyticsOrg);
            $this->addFlash('success', 'Организация удалена из аналитики.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Не удалось удалить организацию: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_analytics_admin_organization_index');
    }
}
