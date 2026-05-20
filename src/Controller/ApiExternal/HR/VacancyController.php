<?php

namespace App\Controller\ApiExternal\HR;

use App\Service\ApiExternal\Vacancy\VacancyApiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

final class VacancyController extends AbstractController
{
    public function __construct(
        private readonly VacancyApiService $vacancyApiService,
    ) {
    }

    #[Route('/hr_vacancies', name: 'app_hr_vacancies')]
    public function index(Request $request): Response
    {
        $filters = [
            'page'           => $request->query->getInt('page', 1),
            'limit'          => 20,
            'isPublished'    => $request->query->get('isPublished'),
            'city'           => $request->query->get('city'),
            'employmentType' => $request->query->get('employmentType'),
            'schedule'       => $request->query->get('schedule'),
            'experience'     => $request->query->get('experience'),
            'search'         => $request->query->get('search'),
            'sort'           => $request->query->get('sort', 'sortOrder'),
            'order'          => $request->query->get('order', 'asc'),
        ];

        $list = null;

        try {
            $list = $this->vacancyApiService->getList($filters);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Не удалось загрузить вакансии: ' . $e->getMessage());
        }

        return $this->render('hr/vacancy/all_recruitment_adds.html.twig', [
            'active_tab' => 'hr_vacancies',
            'list'       => $list,
            'filters'    => $filters,
        ]);
    }

    #[Route('/hr_vacancies/new', name: 'app_hr_vacancies_new')]
    public function new(): Response
    {
        return $this->render('hr/vacancy/new_vacancy.html.twig', [
            'active_tab' => 'hr_vacancies',
        ]);
    }

    #[Route('/hr_vacancies/create', name: 'app_hr_vacancies_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $body = json_decode($request->getContent(), true);

        if (!is_array($body)) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        try {
            $vacancy = $this->vacancyApiService->create($body);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['id' => $vacancy->id]);
    }

    #[Route('/hr_vacancies/{id}', name: 'app_hr_vacancies_show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        try {
            $vacancy = $this->vacancyApiService->getOne($id);
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_hr_vacancies');
        }

        return $this->render('hr/vacancy/show_vacancy.html.twig', [
            'active_tab' => 'hr_vacancies',
            'vacancy'    => $vacancy,
        ]);
    }

    #[Route('/hr_vacancies/{id}/edit', name: 'app_hr_vacancies_edit', requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        try {
            $vacancy = $this->vacancyApiService->getOne($id);
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_hr_vacancies');
        }

        return $this->render('hr/vacancy/edit_vacancy.html.twig', [
            'active_tab' => 'hr_vacancies',
            'vacancy'    => $vacancy,
        ]);
    }

    #[Route('/hr_vacancies/{id}/update', name: 'app_hr_vacancies_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(int $id, Request $request): Response
    {
        $body = json_decode($request->getContent(), true);

        if (!is_array($body)) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        try {
            $this->vacancyApiService->update($id, $body);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/hr_vacancies/{id}/publish', name: 'app_hr_vacancies_publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function publish(int $id, Request $request): Response
    {
        try {
            $this->vacancyApiService->update($id, [
                'isPublished' => (bool) $request->request->get('isPublished'),
            ]);
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_hr_vacancies_show', ['id' => $id]);
    }
}
