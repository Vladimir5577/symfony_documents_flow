<?php

declare(strict_types=1);

namespace App\Controller\ApiExternal\HR;

use App\Service\ApiExternal\VacancyApplication\VacancyApplicationApiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VacancyApplicationResumeProxyController extends AbstractController
{
    public function __construct(
        private readonly VacancyApplicationApiService $vacancyApplicationApiService,
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

        $response = new Response($file['content'], Response::HTTP_OK, [
            'Content-Type' => $file['contentType'],
        ]);

        if ($file['disposition'] !== null) {
            $response->headers->set('Content-Disposition', $file['disposition']);
        }

        return $response;
    }
}
