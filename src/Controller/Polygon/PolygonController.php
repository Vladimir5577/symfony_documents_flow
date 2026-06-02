<?php

declare(strict_types=1);

namespace App\Controller\Polygon;

use App\Entity\Polygon\Polygon;
use App\Repository\Polygon\PolygonRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class PolygonController extends AbstractController
{
    #[Route('/polygons', name: 'app_polygons', methods: ['GET'])]
    public function index(PolygonRepository $polygonRepository): Response
    {
        $polygons = $polygonRepository->findBy([], ['name' => 'ASC']);

        return $this->render('polygon/index.html.twig', [
            'active_tab' => 'polygons',
            'polygons' => $polygons,
        ]);
    }

    #[Route('/polygons/{id}', name: 'app_polygon_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function view(Polygon $polygon): Response
    {
        return $this->render('polygon/view.html.twig', [
            'active_tab' => 'polygons',
            'polygon' => $polygon,
        ]);
    }
}
