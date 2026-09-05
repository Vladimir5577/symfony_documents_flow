<?php

declare(strict_types=1);

namespace App\Controller\ApiExternal\CitizenAppeal;

use App\Service\ApiExternal\CitizenAppeal\CitizenAppealApiService;
use App\Service\ApiExternal\ProxiedFileResponseFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CitizenAppealFileProxyController extends AbstractController
{
    public function __construct(
        private readonly CitizenAppealApiService $citizenAppealApiService,
        private readonly ProxiedFileResponseFactory $responseFactory,
    ) {
    }

    #[Route('/citizen-appeal/file/{id}', name: 'app_citizen_appeal_file_proxy', requirements: ['id' => '\d+'])]
    public function proxy(int $id, Request $request): Response
    {
        try {
            $file = $this->citizenAppealApiService->getFileContent($id, $request->query->getBoolean('download'));
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
            'citizen-appeal-file-' . $id,
        );
    }
}
