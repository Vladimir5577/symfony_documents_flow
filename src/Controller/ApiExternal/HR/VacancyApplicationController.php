<?php

namespace App\Controller\ApiExternal\HR;

use App\Service\ApiExternal\VacancyApplication\VacancyApplicationApiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VacancyApplicationController extends AbstractController
{
    public function __construct(
        private readonly VacancyApplicationApiService $vacancyApplicationApiService,
    ) {
    }

    #[Route('/hr_vacancies_applications', name: 'app_vacancies_applications')]
    public function index(Request $request): Response
    {
        $filters = [
            'page'      => $request->query->getInt('page', 1),
            'limit'     => 20,
            'status'    => $request->query->get('status'),
            'vacancyId' => $request->query->get('vacancyId'),
            'search'    => $request->query->get('search'),
            'dateFrom'  => $request->query->get('dateFrom'),
            'dateTo'    => $request->query->get('dateTo'),
            'sort'      => $request->query->get('sort', 'createdAt'),
            'order'     => $request->query->get('order', 'desc'),
        ];

        $list = null;

        try {
            $list = $this->vacancyApplicationApiService->getList($filters);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Не удалось загрузить отклики: ' . $e->getMessage());
        }

        return $this->render('hr/vacancy_application/all_vacancies_applications.html.twig', [
            'active_tab' => 'hr_vacancies_applications',
            'list'       => $list,
            'filters'    => $filters,
        ]);
    }

    #[Route('/hr_vacancies_applications/{id}', name: 'app_vacancy_application_show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        try {
            $application = $this->vacancyApplicationApiService->getOne($id);
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_vacancies_applications');
        }

        return $this->render('hr/vacancy_application/show_vacancy_application.html.twig', [
            'active_tab'  => 'hr_vacancies_applications',
            'application' => $application,
        ]);
    }

    #[Route('/hr_vacancies_applications/{id}/update', name: 'app_vacancy_application_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(int $id, Request $request): Response
    {
        $status       = $request->request->get('status') ?: null;
        $adminComment = $request->request->get('adminComment') ?: null;

        try {
            $this->vacancyApplicationApiService->update($id, $status, $adminComment);
            $this->addFlash('success', 'Изменения сохранены');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_vacancy_application_show', ['id' => $id]);
    }
}
