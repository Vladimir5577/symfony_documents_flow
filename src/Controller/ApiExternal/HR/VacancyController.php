<?php

namespace App\Controller\ApiExternal\HR;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VacancyController extends AbstractController
{
    #[Route('/hr_vacancies', name: 'app_hr_vacancies')]
    public function index(): Response
    {
        return $this->render('hr/all_recruitment_adds.html.twig', [
            'active_tab' => 'hr_vacancies',
        ]);
    }
}
