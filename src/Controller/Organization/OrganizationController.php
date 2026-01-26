<?php

namespace App\Controller\Organization;

use App\Entity\Organization;
use App\Entity\User;
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
            ->leftJoin('o.childOrganizations', 'co')
            ->addSelect('co')
            ->leftJoin('co.childOrganizations', 'co2')
            ->addSelect('co2')
            ->leftJoin('co2.childOrganizations', 'co3')
            ->addSelect('co3')
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
    #[IsGranted('ROLE_USER')]
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

            $currentUser = $this->getUser();
            $isAdmin = $currentUser instanceof User && $this->isGranted('ROLE_ADMIN');

            if (!$isAdmin && $currentUser instanceof User && !$currentUser->getOrganization()) {
                $this->addFlash('error', 'Не удалось определить организацию. Обратитесь к администратору.');
                return $this->redirectToRoute('create_organization');
            }

            // Создаем новую организацию
            $organization = new Organization();
            $organization->setName(trim((string) ($formData['name'] ?? '')));
            $organization->setDescription(trim((string) ($formData['description'] ?? '')) ?: null);
            $organization->setAddress(trim((string) ($formData['address'] ?? '')) ?: null);
            $organization->setPhone(trim((string) ($formData['phone'] ?? '')) ?: null);
            $organization->setEmail(trim((string) ($formData['email'] ?? '')) ?: null);

            // Админ создаёт главную организацию (без родителя). Пользователь — дочернюю для своей организации.
            if ($isAdmin) {
                $organization->setParent(null);
            } else {
                $organization->setParent($currentUser->getOrganization());
            }

            // Валидация объекта
            $errors = $validator->validate($organization);
            if (count($errors) > 0) {
                // Сохраняем ошибки валидации в сессию для отображения в форме
                foreach ($errors as $error) {
                    $this->addFlash('error', $error->getMessage());
                }
                
                // Возвращаем форму с данными и ошибками
                return $this->render('organization/create_organization.html.twig', [
                    'active_tab' => 'create_organization',
                    'form_data' => $formData,
                    'is_admin' => $isAdmin,
                ]);
            }

            $entityManager->persist($organization);
            $entityManager->flush();

            $this->addFlash('success', 'Организация успешно создана.');
            if ($isAdmin) {
                return $this->redirectToRoute('view_organization', ['id' => $organization->getId()]);
            }
            return $this->redirectToRoute('app_all_organizations');
        }

        // GET запрос - показываем форму
        $currentUser = $this->getUser();
        $isAdmin = $currentUser instanceof User && $this->isGranted('ROLE_ADMIN');

        return $this->render('organization/create_organization.html.twig', [
            'active_tab' => 'create_organization',
            'is_admin' => $isAdmin,
            'form_data' => [],
        ]);
    }
}
