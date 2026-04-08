<?php

declare(strict_types=1);

namespace App\Controller\Analytics;

use App\Entity\Organization\AbstractOrganization;
use App\Repository\Analytics\AnalyticsBoardRepository;
use App\Service\Analytics\OrganizationBoardService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AnalyticsAdminOrganizationBoardController extends AbstractController
{
    #[Route('/analytics/admin/organization/board', name: 'app_analytics_admin_organization_board')]
    public function index(
        OrganizationBoardService $orgBoardService,
        EntityManagerInterface $em,
        AnalyticsBoardRepository $boardRepository,
    ): Response {
        $orgBoards = $orgBoardService->findAll();
        $organizations = $em->getRepository(AbstractOrganization::class)->findAll();
        $boards = $boardRepository->findAll();

        return $this->render('analytics/admin/organization_board/index.html.twig', [
            'orgBoards' => $orgBoards,
            'organizations' => $organizations,
            'boards' => $boards,
            'active_tab' => 'analytics_org_boards',
        ]);
    }

    #[Route('/analytics/admin/organization/board/create', name: 'app_analytics_admin_organization_board_create', methods: ['POST'])]
    public function create(
        Request $request,
        CsrfTokenManagerInterface $csrf,
        OrganizationBoardService $orgBoardService,
        EntityManagerInterface $em,
        AnalyticsBoardRepository $boardRepository,
    ): Response {
        if (!$csrf->isTokenValid(new CsrfToken('org_board_create', $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_admin_organization_board');
        }

        $orgId = $request->request->getInt('organization_id');
        $boardId = $request->request->getInt('board_id');
        $isRequired = (bool) $request->request->get('is_required', false);

        $organization = $em->getRepository(AbstractOrganization::class)->find($orgId);
        $board = $boardRepository->find($boardId);

        if (!$organization) {
            $this->addFlash('error', 'Организация не найдена.');
            return $this->redirectToRoute('app_analytics_admin_organization_board');
        }
        if (!$board) {
            $this->addFlash('error', 'Доска не найдена.');
            return $this->redirectToRoute('app_analytics_admin_organization_board');
        }

        try {
            $orgBoardService->create($organization, $board, $isRequired);
            $this->addFlash('success', 'Доска назначена организации.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_analytics_admin_organization_board');
    }

    #[Route('/analytics/admin/organization/board/{id}/toggle-required', name: 'app_analytics_admin_organization_board_toggle_required', methods: ['POST'])]
    public function toggleRequired(
        int $id,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        OrganizationBoardService $orgBoardService,
    ): Response {
        $orgBoard = $orgBoardService->findById($id);
        if (!$orgBoard) {
            throw $this->createNotFoundException('Назначение не найдено.');
        }

        if (!$csrf->isTokenValid(new CsrfToken('org_board_toggle_' . $id, $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_admin_organization_board');
        }

        try {
            $orgBoardService->toggleRequired($orgBoard);
            $this->addFlash('success', 'Статус обязательности изменён.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_analytics_admin_organization_board');
    }

    #[Route('/analytics/admin/organization/board/{id}/delete', name: 'app_analytics_admin_organization_board_delete', methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        CsrfTokenManagerInterface $csrf,
        OrganizationBoardService $orgBoardService,
    ): Response {
        $orgBoard = $orgBoardService->findById($id);
        if (!$orgBoard) {
            throw $this->createNotFoundException('Назначение не найдено.');
        }

        if (!$csrf->isTokenValid(new CsrfToken('org_board_delete_' . $id, $request->request->getString('_token')))) {
            $this->addFlash('error', 'Неверный CSRF-токен.');
            return $this->redirectToRoute('app_analytics_admin_organization_board');
        }

        try {
            $orgBoardService->delete($orgBoard);
            $this->addFlash('success', 'Доска откреплена от организации.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_analytics_admin_organization_board');
    }
}
