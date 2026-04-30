<?php

declare(strict_types=1);

namespace App\Controller\ApiExternal;

use App\Service\ApiExternal\CitizenAppeal\CitizenAppealApiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CitizenAppealController extends AbstractController
{
    public function __construct(
        private readonly CitizenAppealApiService $citizenAppealApiService,
    ) {
    }

    #[Route('/citizen-appeal', name: 'app_citizen_appeal')]
    public function index(Request $request): Response
    {
        $filters = [
            'page'       => $request->query->getInt('page', 1),
            'limit'      => 20,
            'status'     => $request->query->get('status'),
            'city'       => $request->query->get('city'),
            'appealType' => $request->query->get('appealType'),
            'dateFrom'   => $request->query->get('dateFrom'),
            'dateTo'     => $request->query->get('dateTo'),
            'search'     => $request->query->get('search'),
            'sort'       => $request->query->get('sort', 'id'),
            'order'      => $request->query->get('order', 'desc'),
        ];

        $list = null;

        try {
            $list = $this->citizenAppealApiService->getList($filters);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Не удалось загрузить обращения: ' . $e->getMessage());
        }

        return $this->render('citizen_appeal/all_citizen_appeal.html.twig', [
            'active_tab' => 'citizen_appeal',
            'list'       => $list,
            'filters'    => $filters,
        ]);
    }

    #[Route('/citizen-appeal/{id}', name: 'app_citizen_appeal_show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        try {
            $appeal = $this->citizenAppealApiService->getOne($id);
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_citizen_appeal');
        }

        return $this->render('citizen_appeal/show_citizen_appeal.html.twig', [
            'active_tab' => 'citizen_appeal',
            'appeal'     => $appeal,
        ]);
    }

    #[Route('/citizen-appeal/{id}/update', name: 'app_citizen_appeal_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(int $id, Request $request): Response
    {
        $status       = $request->request->get('status') ?: null;
        $adminComment = $request->request->get('adminComment') ?: null;

        try {
            $this->citizenAppealApiService->update($id, $status, $adminComment);
            $this->addFlash('success', 'Изменения сохранены');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_citizen_appeal_show', ['id' => $id]);
    }
}
