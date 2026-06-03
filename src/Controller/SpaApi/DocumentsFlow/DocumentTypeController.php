<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\DocumentsFlow;

use App\Repository\Document\DocumentTypeRepository;
use App\Service\SpaApi\Documents\DocumentApiPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spa/api/documents-flow')]
final class DocumentTypeController extends AbstractController
{
    public function __construct(
        private readonly DocumentTypeRepository $documentTypeRepository,
        private readonly DocumentApiPresenter $presenter,
    ) {
    }

    #[Route('/types', name: 'spa_api_documents_flow_types', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $types = $this->documentTypeRepository->findBy([], ['name' => 'ASC']);

        return $this->json([
            'types' => array_map(
                fn ($type) => $this->presenter->presentType($type),
                $types,
            ),
        ]);
    }
}
