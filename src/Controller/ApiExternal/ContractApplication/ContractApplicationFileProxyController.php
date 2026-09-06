<?php

declare(strict_types=1);

namespace App\Controller\ApiExternal\ContractApplication;

use App\Service\ApiExternal\ContractApplication\ContractApplicationApiService;
use App\Service\ApiExternal\ProxiedFileResponseFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ContractApplicationFileProxyController extends AbstractController
{
    public function __construct(
        private readonly ContractApplicationApiService $contractApplicationApiService,
        private readonly ProxiedFileResponseFactory $responseFactory,
    ) {
    }

    #[Route('/contract-application/file/{id}', name: 'app_contract_application_file_proxy', requirements: ['id' => '\d+'])]
    public function proxy(int $id, Request $request): Response
    {
        try {
            $file = $this->contractApplicationApiService->getFileContent($id, $request->query->getBoolean('download'));
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 404) {
                throw $this->createNotFoundException('Файл не найден');
            }
            throw $e;
        }

        // BE-13: тип и диспозиция — свои (белый список + магические байты), не апстрима.
        return $this->responseFactory->create(
            $file['content'],
            $file['contentType'],
            $request->query->getBoolean('download'),
            'contract-application-file-' . $id,
        );
    }
}
