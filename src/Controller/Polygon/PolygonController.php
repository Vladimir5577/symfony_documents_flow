<?php

declare(strict_types=1);

namespace App\Controller\Polygon;

use App\Entity\Polygon\Polygon;
use App\Repository\Polygon\PolygonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class PolygonController extends AbstractController
{
    #[Route('/polygons', name: 'app_polygons', methods: ['GET'])]
    public function index(PolygonRepository $polygonRepository): Response
    {
        $polygons = $polygonRepository->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']);

        return $this->render('polygon/index.html.twig', [
            'active_tab' => 'polygons',
            'polygons' => $polygons,
        ]);
    }

    #[Route('/polygons/reorder', name: 'app_polygons_reorder', methods: ['POST'])]
    public function reorder(
        Request $request,
        PolygonRepository $polygonRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrf,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_payload'], 400);
        }

        $token = (string) ($payload['_token'] ?? '');
        if (!$csrf->isTokenValid(new CsrfToken('polygons_reorder', $token))) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_csrf'], 400);
        }

        $order = $payload['order'] ?? null;
        if (!is_array($order)) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_order'], 400);
        }

        $em->wrapInTransaction(function () use ($order, $polygonRepository): void {
            foreach ($order as $entry) {
                $id = (int) ($entry['id'] ?? 0);
                $position = (int) ($entry['position'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $polygon = $polygonRepository->find($id);
                if ($polygon === null) {
                    continue;
                }
                $polygon->setSortOrder($position);
            }
        });

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/polygons/{id}/edit', name: 'app_polygon_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Polygon $polygon,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        if ($request->isMethod('POST')) {
            $formData = $request->request->all();

            if (!$this->isCsrfTokenValid('edit_polygon_' . $polygon->getId(), $formData['_csrf_token'] ?? '')) {
                $this->addFlash('error', 'Неверный CSRF токен.');
                return $this->redirectToRoute('app_polygon_edit', ['id' => $polygon->getId()]);
            }

            $polygon->setName(trim((string) ($formData['name'] ?? '')) ?: null);

            $lat = trim((string) ($formData['gps_lat'] ?? ''));
            $polygon->setGpsLat($lat !== '' ? (float) str_replace(',', '.', $lat) : null);

            $lng = trim((string) ($formData['gps_lng'] ?? ''));
            $polygon->setGpsLng($lng !== '' ? (float) str_replace(',', '.', $lng) : null);

            $polygon->setContactName(trim((string) ($formData['contact_name'] ?? '')) ?: null);
            $polygon->setContactPhone(trim((string) ($formData['contact_phone'] ?? '')) ?: null);
            $polygon->setIsActive((bool) ($formData['is_active'] ?? false));

            $em->flush();

            $this->addFlash('success', 'Полигон успешно обновлён.');
            return $this->redirectToRoute('app_polygon_view', ['id' => $polygon->getId()]);
        }

        return $this->render('polygon/edit.html.twig', [
            'active_tab' => 'polygons',
            'polygon' => $polygon,
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
