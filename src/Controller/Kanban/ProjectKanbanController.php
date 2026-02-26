<?php

namespace App\Controller\Kanban;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectKanbanController extends AbstractController
{
    #[Route('/personal_kanban_projects', name: 'app_personal_kanban_projects')]
    public function personalKanbanProjects(): Response
    {
        return $this->render('kanban/personal_projects.html.twig', [
            'active_tab' => 'personal_kanban_projects',
        ]);
    }

    #[Route('/public_kanban_projects', name: 'app_public_kanban_projects')]
    public function publicKanbanProjects(): Response
    {
        return $this->render('kanban/public_projects.html.twig', [
            'active_tab' => 'public_kanban_projects',
        ]);
    }
}
