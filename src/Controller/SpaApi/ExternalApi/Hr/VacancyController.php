<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\ExternalApi\Hr;

use App\DTO\Vacancy\VacancyDto;
use App\Service\ApiExternal\Vacancy\VacancyApiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spa/api/vacancies')]
#[IsGranted('ROLE_HR')]
final class VacancyController extends AbstractController
{
    public function __construct(
        private readonly VacancyApiService $api,
    ) {
    }

    #[Route('', name: 'spa_api_vacancies_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
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

        try {
            $list = $this->api->getList($filters);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_GATEWAY);
        }

        return $this->json([
            'items'      => array_map($this->present(...), $list->items),
            'pagination' => [
                'page'  => $list->page,
                'limit' => $list->limit,
                'total' => $list->total,
                'pages' => $list->pages,
            ],
        ]);
    }

    #[Route('/{id}', name: 'spa_api_vacancies_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        try {
            $vacancy = $this->api->getOne($id);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => $this->present($vacancy, full: true)]);
    }

    #[Route('', name: 'spa_api_vacancies_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $vacancy = $this->api->create($body);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['data' => $this->present($vacancy, full: true)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spa_api_vacancies_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $vacancy = $this->api->update($id, $body);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['data' => $this->present($vacancy, full: true)]);
    }

    #[Route('/{id}/publish', name: 'spa_api_vacancies_publish', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function publish(int $id, Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || !array_key_exists('isPublished', $body)) {
            return $this->json(['error' => 'isPublished is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $vacancy = $this->api->update($id, ['isPublished' => (bool) $body['isPublished']]);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['data' => $this->present($vacancy, full: true)]);
    }

    /** DTO → массив; city/employmentType/schedule/experience отдаём как {value,label}. */
    private function present(VacancyDto $v, bool $full = false): array
    {
        $base = [
            'id'          => $v->id,
            'slug'        => $v->slug,
            'title'       => $v->title,
            'salary'      => $v->salary,
            'city'        => ['value' => $v->cityValue, 'label' => $v->cityLabel],
            'isPublished' => $v->isPublished,
            'sortOrder'   => $v->sortOrder,
            'createdAt'   => $v->createdAt->format(\DateTimeInterface::ATOM),
        ];

        if (!$full) {
            return $base;
        }

        return $base + [
            'employmentType'   => ['value' => $v->employmentTypeValue, 'label' => $v->employmentTypeLabel],
            'schedule'         => ['value' => $v->scheduleValue, 'label' => $v->scheduleLabel],
            'experience'       => ['value' => $v->experienceValue, 'label' => $v->experienceLabel],
            'shortDescription' => $v->shortDescription,
            'bodyBlocks'       => $v->bodyBlocks,
            'contactEmail'     => $v->contactEmail,
            'contactPhone'     => $v->contactPhone,
            'updatedAt'        => $v->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
