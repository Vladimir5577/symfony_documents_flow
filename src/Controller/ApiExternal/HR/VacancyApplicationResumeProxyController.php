<?php

declare(strict_types=1);

namespace App\Controller\ApiExternal\HR;

use App\Service\ApiExternal\VacancyApplication\VacancyApplicationApiService;
use App\Service\ApiExternal\ProxiedFileResponseFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// BE-05 / SEC-09: кадровые данные кандидатов — только ROLE_HR, как у SPA-близнеца
// (src/Controller/SpaApi/ExternalApi/Hr/*). Раньше маршруты попадали под catch-all ROLE_USER.
#[IsGranted('ROLE_HR')]
final class VacancyApplicationResumeProxyController extends AbstractController
{
    public function __construct(
        private readonly VacancyApplicationApiService $vacancyApplicationApiService,
        private readonly ProxiedFileResponseFactory $responseFactory,
    ) {
    }

    #[Route('/hr_vacancies_applications/{id}/resume', name: 'app_vacancy_application_resume_proxy', requirements: ['id' => '\d+'])]
    public function proxy(int $id, Request $request): Response
    {
        try {
            $file = $this->vacancyApplicationApiService->getResumeContent($id, $request->query->getBoolean('download'));
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 404) {
                throw $this->createNotFoundException('Резюме не найдено');
            }
            throw $e;
        }

        // BE-13: тип и диспозиция — свои (белый список + магические байты), не апстрима.
        return $this->responseFactory->create(
            $file['content'],
            $file['contentType'],
            $request->query->getBoolean('download'),
            'resume-' . $id,
        );
    }
}
