<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\ExternalApi\Hr;

use App\DTO\VacancyApplication\VacancyApplicationDto;
use App\Service\ApiExternal\VacancyApplication\VacancyApplicationApiService;
use App\Service\ApiExternal\ProxiedFileResponseFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spa/api/vacancy-applications')]
#[IsGranted('ROLE_HR')]
final class VacancyApplicationController extends AbstractController
{
    public function __construct(
        private readonly VacancyApplicationApiService $api,
        private readonly ProxiedFileResponseFactory $files,
    ) {
    }

    #[Route('', name: 'spa_api_vacancy_applications_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
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

    #[Route('/{id}', name: 'spa_api_vacancy_applications_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        try {
            $application = $this->api->getOne($id);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => $this->present($application, full: true)]);
    }

    #[Route('/{id}', name: 'spa_api_vacancy_applications_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->api->update($id, $body['status'] ?? null, $body['adminComment'] ?? null);
            $application = $this->api->getOne($id);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['data' => $this->present($application, full: true)]);
    }

    #[Route('/{id}/resume', name: 'spa_api_vacancy_applications_resume', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function resume(int $id, Request $request): Response
    {
        try {
            $file = $this->api->getResumeContent($id, $request->query->getBoolean('download'));
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: Response::HTTP_NOT_FOUND);
        }

        // BE-13: тип и диспозиция — свои (белый список + магические байты), а не
        // апстрима; сервис поле disposition больше не отдаёт.
        return $this->files->create(
            $file['content'],
            $file['contentType'],
            $request->query->getBoolean('download'),
            'resume-' . $id,
        );
    }

    /** DTO → массив; statusLabel уже посчитан в DTO, резюме — через наш прокси. */
    private function present(VacancyApplicationDto $a, bool $full = false): array
    {
        $base = [
            'id'                   => $a->id,
            'vacancyId'            => $a->vacancyId,
            'vacancyTitleSnapshot' => $a->vacancyTitleSnapshot,
            'fio'                  => $a->fio,
            'status'               => $a->status,
            'statusLabel'          => $a->statusLabel,
            'createdAt'            => $a->createdAt->format(\DateTimeInterface::ATOM),
        ];

        if (!$full) {
            return $base;
        }

        return $base + [
            'vacancySlug'  => $a->vacancySlug,
            'phone'        => $a->phone,
            'email'        => $a->email,
            'coverLetter'  => $a->coverLetter,
            'adminComment' => $a->adminComment,
            'updatedAt'    => $a->updatedAt->format(\DateTimeInterface::ATOM),
            'resume'       => $a->resume === null ? null : [
                'originalName' => $a->resume->originalName,
                'mimeType'     => $a->resume->mimeType,
                'size'         => $a->resume->size,
                'sizeLabel'    => $a->resume->getFileSizeFormatted(),
                'url'          => '/spa/api/vacancy-applications/' . $a->id . '/resume',
            ],
        ];
    }
}
