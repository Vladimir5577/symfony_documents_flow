<?php

declare(strict_types=1);

namespace App\Controller\ApiExternal\ContractApplication;

use App\Service\ApiExternal\ContractApplication\ContractApplicationApiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Http\ProxiedFileResponse;

final class ContractApplicationFileProxyController extends AbstractController
{
    public function __construct(
        private readonly ContractApplicationApiService $contractApplicationApiService,
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

        return ProxiedFileResponse::create($file);
    }
}
