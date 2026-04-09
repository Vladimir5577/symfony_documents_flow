<?php

declare(strict_types=1);

namespace App\Controller\Analytics;

use App\Entity\Analytics\AnalyticsReport;
use App\Entity\Organization\AbstractOrganization;
use App\Service\Analytics\ApproveReportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MANAGER')]
final class AnalyticsApprovalController extends AbstractController
{
    /**
     * Текущая организация пользователя + все её родители.
     *
     * @return int[]
     */
    private function getOrganizationHierarchyIds(AbstractOrganization $organization): array
    {
        $ids = [];
        $current = $organization;

        while ($current !== null) {
            $id = $current->getId();
            if ($id === null) {
                break;
            }

            $ids[] = $id;
            $current = $current->getParent();
        }

        return array_values(array_unique($ids));
    }

    #[Route('/analytics/approval', name: 'app_analytics_approval')]
    public function index(
        ApproveReportService $approveService,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $userOrg = $user->getOrganization();
        if (!$isAdmin && !$userOrg) {
            throw $this->createAccessDeniedException('Вам не назначена организация.');
        }

        $orgIds = (!$isAdmin && $userOrg) ? $this->getOrganizationHierarchyIds($userOrg) : [];
        $pendingReports = $isAdmin
            ? $approveService->findPendingReports()
            : $approveService->findPendingReportsByOrganizationIds($orgIds);

        // Статистика: сколько организаций ждут утверждении
        if ($isAdmin) {
            $organizations = $em->getRepository(AbstractOrganization::class)->findAll();
        } else {
            $organizations = $em->getRepository(AbstractOrganization::class)->createQueryBuilder('o')
                ->andWhere('o.id IN (:orgIds)')
                ->setParameter('orgIds', $orgIds)
                ->getQuery()
                ->getResult();
        }

        $stats = [];
        foreach ($organizations as $org) {
            if (!$org) {
                continue;
            }

            $count = $em->getRepository(AnalyticsReport::class)->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->andWhere('r.status = :status')
                ->andWhere('r.organization = :org')
                ->setParameter('status', \App\Enum\Analytics\AnalyticsReportStatus::Submitted)
                ->setParameter('org', $org)
                ->getQuery()
                ->getSingleScalarResult();

            if ($count > 0) {
                $stats[] = ['organization' => $org->getName(), 'pending' => (int) $count];
            }
        }

        return $this->render('analytics/approval/index.html.twig', [
            'pendingReports' => $pendingReports,
            'stats' => $stats,
            'active_tab' => 'analytics_approval',
        ]);
    }

    #[Route('/analytics/approval/{id}/approve', name: 'app_analytics_approval_approve', methods: ['POST'])]
    public function approve(
        int $id,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        ApproveReportService $approveService,
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $userOrg = $user->getOrganization();
        if (!$isAdmin && !$userOrg) {
            throw $this->createAccessDeniedException('Вам не назначена организация.');
        }

        $report = $isAdmin
            ? $approveService->findById($id)
            : $approveService->findByIdForOrganizationIds($id, $this->getOrganizationHierarchyIds($userOrg));
        if (!$report) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        if (!$csrf->isTokenValid(new CsrfToken('report_approve_' . $id, $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_approval');
        }

        try {
            $approveService->approve($report, $user);
            $this->addFlash('success', 'Отчёт утверждён.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_analytics_approval');
    }
}
