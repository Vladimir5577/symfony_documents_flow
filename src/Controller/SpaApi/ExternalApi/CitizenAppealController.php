<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\ExternalApi;

use App\DTO\CitizenAppeal\CitizenAppealDto;
use App\Service\ApiExternal\CitizenAppeal\CitizenAppealApiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spa/api/citizen-appeals')]
#[IsGranted('ROLE_CITIZEN_APPEAL')]
final class CitizenAppealController extends AbstractController
{
    public function __construct(
        private readonly CitizenAppealApiService $api,
    ) {
    }

    #[Route('', name: 'spa_api_citizen_appeals_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
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

    #[Route('/{id}', name: 'spa_api_citizen_appeals_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        try {
            $appeal = $this->api->getOne($id);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => $this->present($appeal, full: true)]);
    }

    #[Route('/{id}', name: 'spa_api_citizen_appeals_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->api->update($id, $body['status'] ?? null, $body['adminComment'] ?? null);
            $appeal = $this->api->getOne($id);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['data' => $this->present($appeal, full: true)]);
    }

    #[Route('/files/{id}', name: 'spa_api_citizen_appeals_file', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function fileProxy(int $id, Request $request): Response
    {
        try {
            $file = $this->api->getFileContent($id, $request->query->getBoolean('download'));
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: Response::HTTP_NOT_FOUND);
        }

        $response = new Response($file['content'], Response::HTTP_OK, ['Content-Type' => $file['contentType']]);
        if ($file['disposition'] !== null) {
            $response->headers->set('Content-Disposition', $file['disposition']);
        }

        return $response;
    }

    /** DTO → массив с готовыми лейблами; url файлов — на наш прокси, upstream-url наружу не отдаём. */
    private function present(CitizenAppealDto $a, bool $full = false): array
    {
        $base = [
            'id'              => $a->id,
            'publicId'        => $a->publicId,
            'fio'             => $a->fio,
            'appealType'      => $a->appealType,
            'appealTypeLabel' => $a->getAppealTypeLabel(),
            'city'            => $a->city,
            'status'          => $a->status,
            'statusLabel'     => $a->getStatusLabel(),
            'createdAt'       => $a->createdAt->format(\DateTimeInterface::ATOM),
        ];

        if (!$full) {
            return $base;
        }

        return $base + [
            'phone'        => $a->phone,
            'email'        => $a->email,
            'address'      => $a->address,
            'message'      => $a->message,
            'replyTo'      => $a->replyTo,
            'adminComment' => $a->adminComment,
            'updatedAt'    => $a->updatedAt->format(\DateTimeInterface::ATOM),
            'files'        => array_map(static fn($f) => [
                'id'           => $f->id,
                'originalName' => $f->originalName,
                'mimeType'     => $f->mimeType,
                'fileSize'     => $f->fileSize,
                'sizeLabel'    => $f->getFileSizeFormatted(),
                'url'          => '/spa/api/citizen-appeals/files/' . $f->id,
            ], $a->files),
        ];
    }
}
