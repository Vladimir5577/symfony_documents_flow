<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\ExternalApi;

use App\DTO\ContractApplication\ContractApplicationDto;
use App\Service\ApiExternal\ContractApplication\ContractApplicationApiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spa/api/contract-applications')]
#[IsGranted('ROLE_CONTRACT_APPLICATION')]
final class ContractApplicationController extends AbstractController
{
    public function __construct(
        private readonly ContractApplicationApiService $api,
    ) {
    }

    #[Route('', name: 'spa_api_contract_applications_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
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

    #[Route('/{id}', name: 'spa_api_contract_applications_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        try {
            $app = $this->api->getOne($id);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => $this->present($app, full: true)]);
    }

    #[Route('/{id}', name: 'spa_api_contract_applications_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->api->update($id, $body['status'] ?? null, $body['adminComment'] ?? null);
            $app = $this->api->getOne($id);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['data' => $this->present($app, full: true)]);
    }

    #[Route('/files/{id}', name: 'spa_api_contract_applications_file', requirements: ['id' => '\d+'], methods: ['GET'])]
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

    /** DTO → массив; вложенные блоки (consumer/requisites/…) отдаём как есть, файлы — через наш прокси. */
    private function present(ContractApplicationDto $a, bool $full = false): array
    {
        $base = [
            'id'                => $a->id,
            'publicId'          => $a->publicId,
            'consumerType'      => $a->consumerType,
            'consumerTypeLabel' => $a->getConsumerTypeLabel(),
            'consumerName'      => $a->consumerName,
            'organization'      => $a->organization,
            'status'            => $a->status,
            'statusLabel'       => $a->getStatusLabel(),
            'createdAt'         => $a->createdAt->format(\DateTimeInterface::ATOM),
        ];

        if (!$full) {
            return $base;
        }

        return $base + [
            'primaryPhone' => $a->primaryPhone,
            'primaryEmail' => $a->primaryEmail,
            'adminComment' => $a->adminComment,
            'updatedAt'    => $a->updatedAt->format(\DateTimeInterface::ATOM),
            'consumer'     => $a->consumer,
            'requisites'   => $a->requisites,
            'signer'       => $a->signer,
            'waste'        => $a->waste,
            'site'         => $a->site,
            'containers'   => $a->containers,
            'extra'        => $a->extra,
            'files'        => array_map(static fn($f) => [
                'id'           => $f->id,
                'originalName' => $f->originalName,
                'mimeType'     => $f->mimeType,
                'fileSize'     => $f->fileSize,
                'sizeLabel'    => $f->getFileSizeFormatted(),
                'url'          => '/spa/api/contract-applications/files/' . $f->id,
            ], $a->files),
        ];
    }
}
