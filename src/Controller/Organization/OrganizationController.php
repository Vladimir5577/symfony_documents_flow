<?php

namespace App\Controller\Organization;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
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
    public function viewOrganization(int $id, Request $request, OrganizationRepository $organizationRepository, UserRepository $userRepository): Response
    {
        $organization = $organizationRepository->createQueryBuilder('o')
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

        $users = $userRepository->findByOrganization($organization);

        $fromId = $request->query->get('from');
        $backOrganization = null;
        if ($fromId !== null && $fromId !== '' && (int) $fromId !== $id) {
            $fromOrg = $organizationRepository->find((int) $fromId);
            if ($fromOrg !== null) {
                $backOrganization = $fromOrg;
            }
        }

        return $this->render('organization/view_organization.html.twig', [
            'active_tab' => 'view_organization',
            'organization' => $organization,
            'users' => $users,
            'back_organization' => $backOrganization,
        ]);
    }

    #[Route('/all_organizations', name: 'app_all_organizations', methods: ['GET'])]
    public function allOrganizations(Request $request, OrganizationRepository $organizationRepository): Response
    {
        $currentUser = $this->getUser();

        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException('Необходима авторизация');
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN');

        if ($isAdmin) {
            // Админ видит все родительские организации с пагинацией
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

        // Обычный пользователь видит только свою организацию
        $userOrganization = $currentUser->getOrganization();

        if (!$userOrganization) {
            $this->addFlash('error', 'Не удалось определить организацию. Обратитесь к администратору.');
            return $this->redirectToRoute('app_dashboard');
        }

        // Показываем только организацию пользователя
        $organizations = [$userOrganization];

        return $this->render('organization/all_organizations.html.twig', [
            'active_tab' => 'all_organizations',
            'controller_name' => 'OrganizationController',
            'organizations' => $organizations,
            'pagination' => [
                'current_page' => 1,
                'total_pages' => 1,
                'total_items' => 1,
                'items_per_page' => 1,
            ],
        ]);
    }

    #[Route('/edit_organization/{id}', name: 'edit_organization', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function editOrganization(
        int $id,
        Request $request,
        OrganizationRepository $organizationRepository,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator
    ): Response {
        $organization = $organizationRepository->find($id);

        if (!$organization) {
            throw $this->createNotFoundException('Организация не найдена');
        }

        $currentUser = $this->getUser();
        $isAdmin = $currentUser instanceof User && $this->isGranted('ROLE_ADMIN');
        $userOrganization = $currentUser instanceof User ? $currentUser->getOrganization() : null;

        // Загружаем дерево организаций для пикера
        $organizationTree = $organizationRepository->getOrganizationTree($isAdmin ? null : $userOrganization);
        $organizationsWithChildren = [];
        if (!empty($organizationTree)) {
            foreach ($organizationTree as $org) {
                $loadedOrg = $organizationRepository->findWithChildren($org->getId());
                if ($loadedOrg) {
                    $organizationsWithChildren[] = $loadedOrg;
                }
            }
        }

        if ($request->isMethod('POST')) {
            $formData = $request->request->all();

            // Валидация CSRF токена
            if (!$this->isCsrfTokenValid('edit_organization_' . $id, $formData['_csrf_token'] ?? '')) {
                $this->addFlash('error', 'Неверный CSRF токен.');
                return $this->redirectToRoute('edit_organization', ['id' => $id]);
            }

            $organization->setName(trim((string) ($formData['name'] ?? '')));
            $organization->setDescription(trim((string) ($formData['description'] ?? '')) ?: null);
            $organization->setAddress(trim((string) ($formData['address'] ?? '')) ?: null);
            $organization->setPhone(trim((string) ($formData['phone'] ?? '')) ?: null);
            $organization->setEmail(trim((string) ($formData['email'] ?? '')) ?: null);

            // Обрабатываем родительскую организацию
            $parentId = (int) ($formData['parent_id'] ?? 0);
            if ($parentId > 0 && $parentId !== $id) {
                $parentOrg = $organizationRepository->find($parentId);
                if ($parentOrg) {
                    $organization->setParent($parentOrg);
                }
            } else {
                $organization->setParent(null);
            }

            // Валидация объекта
            $errors = $validator->validate($organization);
            if (count($errors) > 0) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error->getMessage());
                }
                return $this->render('organization/edit_organization.html.twig', [
                    'active_tab' => 'all_organizations',
                    'organization' => $organization,
                    'is_admin' => $isAdmin,
                    'organizations' => $organizationsWithChildren,
                ]);
            }

            $entityManager->flush();

            $this->addFlash('success', 'Организация успешно обновлена.');
            return $this->redirectToRoute('view_organization', ['id' => $organization->getId()]);
        }

        return $this->render('organization/edit_organization.html.twig', [
            'active_tab' => 'all_organizations',
            'organization' => $organization,
            'is_admin' => $isAdmin,
            'organizations' => $organizationsWithChildren,
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
        $currentUser = $this->getUser();
        $isAdmin = $currentUser instanceof User && $this->isGranted('ROLE_ADMIN');
        $userOrganization = $currentUser instanceof User ? $currentUser->getOrganization() : null;

        // Загружаем дерево организаций для пикера
        $organizationTree = $organizationRepository->getOrganizationTree($isAdmin ? null : $userOrganization);
        $organizationsWithChildren = [];
        if (!empty($organizationTree)) {
            foreach ($organizationTree as $org) {
                $loadedOrg = $organizationRepository->findWithChildren($org->getId());
                if ($loadedOrg) {
                    $organizationsWithChildren[] = $loadedOrg;
                }
            }
        }

        $renderForm = function (array $formData = []) use ($isAdmin, $organizationsWithChildren): Response {
            return $this->render('organization/create_organization.html.twig', [
                'active_tab' => 'create_organization',
                'form_data' => $formData,
                'is_admin' => $isAdmin,
                'organizations' => $organizationsWithChildren,
            ]);
        };

        if (!$request->isMethod('POST')) {
            return $renderForm([]);
        }

        $formData = $request->request->all();

        // Валидация CSRF токена
        if (!$this->isCsrfTokenValid('create_organization', $formData['_csrf_token'] ?? '')) {
            $this->addFlash('error', 'Неверный CSRF токен.');
            return $this->redirectToRoute('create_organization');
        }

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

        // Обрабатываем родительскую организацию
        $parentId = (int) ($formData['parent_id'] ?? 0);
        if ($parentId > 0) {
            $parentOrg = $organizationRepository->find($parentId);
            if ($parentOrg) {
                $organization->setParent($parentOrg);
            }
        }

        // Валидация объекта
        $errors = $validator->validate($organization);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error->getMessage());
            }
            return $renderForm($formData);
        }

        $entityManager->persist($organization);
        $entityManager->flush();

        $this->addFlash('success', 'Организация успешно создана.');
        return $this->redirectToRoute('view_organization', ['id' => $organization->getId()]);
    }

    #[Route('/delete_organization/{id}', name: 'app_delete_organization', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deleteOrganization(int $id, Request $request, OrganizationRepository $organizationRepository): Response
    {
        if (!$this->isCsrfTokenValid('delete_organization_' . $id, $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Неверный CSRF токен.');
            return $this->redirectToRoute('view_organization', ['id' => $id]);
        }

        if (!$organizationRepository->deleteById($id)) {
            throw $this->createNotFoundException('Организация не найдена');
        }

        $this->addFlash('success', 'Организация удалена.');
        return $this->redirectToRoute('app_all_organizations');
    }
}
