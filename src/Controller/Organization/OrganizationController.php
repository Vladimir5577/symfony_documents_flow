<?php

namespace App\Controller\Organization;

use App\Entity\Organization;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class OrganizationController extends AbstractController
{
    #[Route('/view_organization/{id}', name: 'view_organization', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function viewOrganization(int $id, OrganizationRepository $organizationRepository): Response
    {
        $organization = $organizationRepository->createQueryBuilder('o')
            ->leftJoin('o.departments', 'd')
            ->addSelect('d')
            ->leftJoin('d.departmentDivisions', 'dd')
            ->addSelect('dd')
            ->where('o.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$organization) {
            throw $this->createNotFoundException('Организация не найдена');
        }

        return $this->render('organization/view_organization.html.twig', [
            'active_tab' => 'view_organization',
            'organization' => $organization,
        ]);
    }

    #[Route('/all_organizations', name: 'app_all_organizations', methods: ['GET'])]
    public function allOrganizations(Request $request, OrganizationRepository $organizationRepository): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 10; // Количество организаций на странице

        $pagination = $organizationRepository->findPaginated($page, $limit);

        return $this->render('organization/all_organizations.html.twig', [
            'active_tab' => 'all_organizations',
            'controller_name' => 'OrganizationController',
            'organizations' => $pagination['organizations'],
            'pagination' => [
                'current_page' => $pagination['page'],
                'total_pages' => $pagination['totalPages'],
                'total_items' => $pagination['total'],
                'items_per_page' => $pagination['limit'],
            ],
        ]);
    }

    #[Route('/create_organization', name: 'create_organization', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createOrganization(
        Request $request,
        OrganizationRepository $organizationRepository,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator
    ): Response {
        if ($request->isMethod('POST')) {
            $formData = $request->request->all();

            // Валидация CSRF токена
            if (!$this->isCsrfTokenValid('create_organization', $formData['_csrf_token'] ?? '')) {
                $this->addFlash('error', 'Неверный CSRF токен.');
                return $this->redirectToRoute('create_organization');
            }

            // Создаем новую организацию
            $organization = new Organization();
            $organization->setName(trim((string) ($formData['name'] ?? '')));
            $organization->setDescription(trim((string) ($formData['description'] ?? '')) ?: null);
            $organization->setAddress(trim((string) ($formData['address'] ?? '')) ?: null);
            $organization->setPhone(trim((string) ($formData['phone'] ?? '')) ?: null);
            $organization->setEmail(trim((string) ($formData['email'] ?? '')) ?: null);

            // Устанавливаем родительскую организацию, если указана
            $parentId = (int) ($formData['parent_id'] ?? 0);
            if ($parentId > 0) {
                $parent = $organizationRepository->find($parentId);
                if ($parent) {
                    // Проверка на циклическую зависимость: организация не может быть родителем самой себя
                    if ($parent->getId() !== null) {
                        $organization->setParent($parent);
                    }
                }
            }

            // Валидация объекта
            $errors = $validator->validate($organization);
            if (count($errors) > 0) {
                // Сохраняем ошибки валидации в сессию для отображения в форме
                foreach ($errors as $error) {
                    $this->addFlash('error', $error->getMessage());
                }
                
                // Возвращаем форму с данными и ошибками
                $organizations = $organizationRepository->findAll();
                return $this->render('organization/create_organization.html.twig', [
                    'active_tab' => 'create_organization',
                    'organizations' => $organizations,
                    'form_data' => $formData,
                ]);
            }

            $entityManager->persist($organization);
            $entityManager->flush();

            $this->addFlash('success', 'Организация успешно создана.');
            return $this->redirectToRoute('view_organization', ['id' => $organization->getId()]);
        }

        // GET запрос - показываем форму
        $organizations = $organizationRepository->findAll();

        return $this->render('organization/create_organization.html.twig', [
            'active_tab' => 'create_organization',
            'organizations' => $organizations,
        ]);
    }
}
