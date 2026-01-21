<?php

namespace App\Controller\Document;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DocumentController extends AbstractController
{
    #[Route('/new_document', name: 'app_new_document')]
    public function index(): Response
    {
        return $this->render('document/new_document.html.twig', [
            'active_tab' => 'new_document',
            'controller_name' => 'DocumentController',
        ]);
    }
}
