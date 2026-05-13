<?php

declare(strict_types=1);

namespace App\Controller\ApiExternal\ContractApplication;

use App\Service\ApiExternal\ContractApplication\ContractApplicationApiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ContractApplicationController extends AbstractController
{
    public function __construct(
        private readonly ContractApplicationApiService $contractApplicationApiService,
    ) {
    }

    #[Route('/contract-application', name: 'app_contract_application')]
    public function index(Request $request): Response
    {
        $filters = [
            'page'         => $request->query->getInt('page', 1),
            'limit'        => 20,
            'status'       => $request->query->get('status'),
            'consumerType' => $request->query->get('consumerType'),
            'dateFrom'     => $request->query->get('dateFrom'),
            'dateTo'       => $request->query->get('dateTo'),
            'search'       => $request->query->get('search'),
            'sort'         => $request->query->get('sort', 'id'),
            'order'        => $request->query->get('order', 'desc'),
        ];

        $list = null;

        try {
            $list = $this->contractApplicationApiService->getList($filters);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Не удалось загрузить заявки: ' . $e->getMessage());
        }

        return $this->render('contract_application/all_contract_applications.html.twig', [
            'active_tab' => 'contract_application',
            'list'       => $list,
            'filters'    => $filters,
        ]);
    }

    #[Route('/contract-application/{id}', name: 'app_contract_application_show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        try {
            $application = $this->contractApplicationApiService->getOne($id);
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_contract_application');
        }

        return $this->render('contract_application/show_contract_application.html.twig', [
            'active_tab'  => 'contract_application',
            'application' => $application,
        ]);
    }

    #[Route('/contract-application/{id}/update', name: 'app_contract_application_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(int $id, Request $request): Response
    {
        $status       = $request->request->get('status') ?: null;
        $adminComment = $request->request->get('adminComment') ?: null;

        try {
            $this->contractApplicationApiService->update($id, $status, $adminComment);
            $this->addFlash('success', 'Изменения сохранены');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_contract_application_show', ['id' => $id]);
    }
}
