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

#[IsGranted('ROLE_ADMIN')]
final class AnalyticsApprovalController extends AbstractController
{
    #[Route('/analytics/approval', name: 'app_analytics_approval')]
    public function index(
        ApproveReportService $approveService,
        EntityManagerInterface $em,
    ): Response {
        $pendingReports = $approveService->findPendingReports();

        // Статистика: сколько организаций ждут утверждении
        $organizations = $em->getRepository(AbstractOrganization::class)->findAll();

        $stats = [];
        foreach ($organizations as $org) {
            $count = $em->getRepository(AnalyticsReport::class)->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->andWhere('r.organization = :org')
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
        $report = $approveService->findById($id);
        if (!$report) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        if (!$csrf->isTokenValid(new CsrfToken('report_approve_' . $id, $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_approval');
        }

        try {
            $approveService->approve($report, $this->getUser());
            $this->addFlash('success', 'Отчёт утверждён.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_analytics_approval');
    }
}
