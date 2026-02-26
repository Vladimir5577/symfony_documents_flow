<?php

namespace App\Controller\Organization;

use App\Entity\Organization\AbstractOrganization;
use App\Entity\Organization\AbstractOrganizationWithDetails;
use App\Entity\Organization\Department;
use App\Entity\Organization\Filial;
use App\Entity\Organization\Organization;
use App\Entity\User\User;
use App\Enum\OrganizationType;
use App\Enum\TaxType;
use App\Repository\Organization\OrganizationRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class OrganizationController extends AbstractController
{
    #[Route('/view_organization/{id}', name: 'view_organization', requirements: ['id' => '\d+'])]
    public function viewOrganization(int $id, Request $request, EntityManagerInterface $em, UserRepository $userRepository): Response
    {
        $unitRepo = $em->getRepository(AbstractOrganization::class);
        $organization = $unitRepo->createQueryBuilder('o')
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
            $fromUnit = $unitRepo->find((int) $fromId);
            if ($fromUnit !== null) {
                $backOrganization = $fromUnit;
            }
        }

        $organizationType = $organization instanceof Filial
            ? OrganizationType::FILIAL
            : ($organization instanceof Department ? OrganizationType::DEPARTMENT : OrganizationType::ORGANIZATION);

        return $this->render('organization/view_organization.html.twig', [
            'active_tab' => 'view_organization',
            'organization' => $organization,
            'organization_type' => $organizationType,
            'type_organization' => $organizationType->getLabel(),
            'users' => $users,
            'back_organization' => $backOrganization,
        ]);
    }

    #[Route('/organization/{id}/requisites.txt', name: 'organization_download_requisites', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function downloadRequisites(int $id, EntityManagerInterface $em): Response
    {
        $organization = $em->getRepository(AbstractOrganization::class)->find($id);
        if (!$organization) {
            throw $this->createNotFoundException('Организация не найдена');
        }

        $lines = [
            'РЕКВИЗИТЫ',
            str_repeat('—', 40),
            'Краткое наименование: ' . ($organization->getShortName() ?? ''),
            'Полное наименование: ' . ($organization->getFullName() ?? ''),
            '',
            'Юридический адрес: ' . ($organization->getLegalAddress() ?? ''),
            'Фактический адрес: ' . ($organization->getActualAddress() ?? ''),
            '',
            'Телефон: ' . ($organization->getPhone() ?? ''),
            'Email: ' . ($organization->getEmail() ?? ''),
        ];

        if ($organization->getDescription()) {
            $lines[] = '';
            $lines[] = 'Описание: ' . $organization->getDescription();
        }

        if ($organization instanceof AbstractOrganizationWithDetails) {
            $lines[] = '';
            $lines[] = str_repeat('—', 40);
            $lines[] = 'ИНН: ' . ($organization->getInn() ?? '');
            $lines[] = 'КПП: ' . ($organization->getKpp() ?? '');
            $lines[] = 'ОГРН/ОГРНИП: ' . ($organization->getOgrn() ?? '');
            $regDate = $organization->getRegistrationDate();
            $lines[] = 'Дата регистрации: ' . ($regDate ? $regDate->format('d.m.Y') : '');
            $lines[] = 'Орган регистрации: ' . ($organization->getRegistrationOrgan() ?? '');
            $lines[] = '';
            $lines[] = 'Банк: ' . ($organization->getBankName() ?? '');
            $lines[] = 'БИК: ' . ($organization->getBik() ?? '');
            $lines[] = 'Расчётный счёт: ' . ($organization->getBankAccount() ?? '');
            $taxType = $organization->getTaxType();
            $lines[] = 'Тип налогообложения: ' . ($taxType ? $taxType->getLabel() : '');
        }

        $content = implode("\r\n", $lines);

        $filename = sprintf('requisites-%d.txt', $id);

        return new Response($content, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
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
            // Админ видит все родительские организации с пагинацией и поиском
            $page = max(1, (int) $request->query->get('page', 1));
            $search = trim((string) $request->query->get('search', ''));
            $limit = 10;

            $pagination = $organizationRepository->findPaginated($page, $limit, $search);

            return $this->render('organization/all_organizations.html.twig', [
                'active_tab' => 'all_organizations',
                'controller_name' => 'OrganizationController',
                'organizations' => $pagination['organizations'],
                'search' => $search,
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

        $search = trim((string) $request->query->get('search', ''));
        $organizations = [$userOrganization];
        if ($search !== '') {
            $term = mb_strtolower($search);
            $match = false
                || ($userOrganization->getShortName() && mb_strpos(mb_strtolower($userOrganization->getShortName()), $term) !== false)
                || ($userOrganization->getFullName() && mb_strpos(mb_strtolower($userOrganization->getFullName()), $term) !== false)
                || ($userOrganization->getLegalAddress() && mb_strpos(mb_strtolower($userOrganization->getLegalAddress()), $term) !== false)
                || ($userOrganization->getActualAddress() && mb_strpos(mb_strtolower($userOrganization->getActualAddress()), $term) !== false)
                || ($userOrganization->getPhone() && mb_strpos(mb_strtolower($userOrganization->getPhone()), $term) !== false)
                || ($userOrganization->getEmail() && mb_strpos(mb_strtolower($userOrganization->getEmail()), $term) !== false);
            if (!$match) {
                $organizations = [];
            }
        }

        return $this->render('organization/all_organizations.html.twig', [
            'active_tab' => 'all_organizations',
            'controller_name' => 'OrganizationController',
            'organizations' => $organizations,
            'search' => $search,
            'pagination' => [
                'current_page' => 1,
                'total_pages' => 1,
                'total_items' => count($organizations),
                'items_per_page' => 1,
            ],
        ]);
    }

    #[Route('/organizations/search', name: 'app_organizations_search', methods: ['GET'])]
    public function searchOrganizations(Request $request, OrganizationRepository $organizationRepository): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return new JsonResponse(['organizations' => [], 'pagination' => ['current_page' => 1, 'total_pages' => 1, 'total_items' => 0, 'items_per_page' => 10]], 403);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $search = trim((string) $request->query->get('search', ''));
        $limit = 10;

        if ($this->isGranted('ROLE_ADMIN')) {
            $pagination = $organizationRepository->findPaginated($page, $limit, $search);
            $organizations = $pagination['organizations'];
            $paginationData = [
                'current_page' => $pagination['page'],
                'total_pages' => $pagination['totalPages'],
                'total_items' => $pagination['total'],
                'items_per_page' => $pagination['limit'],
            ];
        } else {
            $userOrganization = $currentUser->getOrganization();
            $organizations = [];
            if ($userOrganization) {
                if ($search === '') {
                    $organizations = [$userOrganization];
                } else {
                    $term = mb_strtolower($search);
                    $match = false
                        || ($userOrganization->getShortName() && mb_strpos(mb_strtolower($userOrganization->getShortName()), $term) !== false)
                        || ($userOrganization->getFullName() && mb_strpos(mb_strtolower($userOrganization->getFullName()), $term) !== false)
                        || ($userOrganization->getLegalAddress() && mb_strpos(mb_strtolower($userOrganization->getLegalAddress()), $term) !== false)
                || ($userOrganization->getActualAddress() && mb_strpos(mb_strtolower($userOrganization->getActualAddress()), $term) !== false)
                        || ($userOrganization->getPhone() && mb_strpos(mb_strtolower($userOrganization->getPhone()), $term) !== false)
                        || ($userOrganization->getEmail() && mb_strpos(mb_strtolower($userOrganization->getEmail()), $term) !== false);
                    if ($match) {
                        $organizations = [$userOrganization];
                    }
                }
            }
            $paginationData = [
                'current_page' => 1,
                'total_pages' => 1,
                'total_items' => count($organizations),
                'items_per_page' => $limit,
            ];
        }

        $organizationsData = [];
        foreach ($organizations as $org) {
            $organizationsData[] = [
                'id' => $org->getId(),
                'shortName' => $org->getShortName(),
                'fullName' => $org->getFullName(),
                'legalAddress' => $org->getLegalAddress() ?? '-',
                'actualAddress' => $org->getActualAddress() ?? '-',
                'phone' => $org->getPhone() ?? '-',
                'email' => $org->getEmail() ?? '-',
                'viewUrl' => $this->generateUrl('view_organization', ['id' => $org->getId()]),
            ];
        }

        return new JsonResponse([
            'organizations' => $organizationsData,
            'pagination' => $paginationData,
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
        $organizationTree = $organizationRepository->getOrganizationTree($isAdmin ? null : ($userOrganization ? $userOrganization->getRootOrganization() : null));
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

            $organization->setShortName(trim((string) ($formData['short_name'] ?? '')));
            $organization->setFullName(trim((string) ($formData['full_name'] ?? '')));
            $organization->setDescription(trim((string) ($formData['description'] ?? '')) ?: null);
            $organization->setLegalAddress(trim((string) ($formData['legal_address'] ?? '')) ?: null);
            $organization->setActualAddress(trim((string) ($formData['actual_address'] ?? '')) ?: null);
            $organization->setPhone(trim((string) ($formData['phone'] ?? '')) ?: null);
            $organization->setEmail(trim((string) ($formData['email'] ?? '')) ?: null);
            $organization->setInn(trim((string) ($formData['inn'] ?? '')) ?: null);
            $organization->setKpp(trim((string) ($formData['kpp'] ?? '')) ?: null);
            $organization->setOgrn(trim((string) ($formData['ogrn'] ?? '')) ?: null);
            $organization->setRegistrationOrgan(trim((string) ($formData['registration_organ'] ?? '')) ?: null);
            $organization->setBankName(trim((string) ($formData['bank_name'] ?? '')) ?: null);
            $organization->setBik(trim((string) ($formData['bik'] ?? '')) ?: null);
            $organization->setBankAccount(trim((string) ($formData['bank_account'] ?? '')) ?: null);
            $organization->setTaxType(TaxType::tryFrom(trim((string) ($formData['tax_type'] ?? ''))));
            $regDate = trim((string) ($formData['registration_date'] ?? ''));
            $organization->setRegistrationDate($regDate !== '' ? (\DateTimeImmutable::createFromFormat('Y-m-d', $regDate) ?: null) : null);

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
                    'tax_type_choices' => TaxType::getChoices(),
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
            'tax_type_choices' => TaxType::getChoices(),
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
        $organizationTree = $organizationRepository->getOrganizationTree($isAdmin ? null : ($userOrganization ? $userOrganization->getRootOrganization() : null));
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
                'tax_type_choices' => TaxType::getChoices(),
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

        $organizationType = OrganizationType::tryFrom((string) ($formData['discriminator'] ?? '')) ?? OrganizationType::ORGANIZATION;

        $parentId = (int) ($formData['parent_id'] ?? 0);
        $parentOrg = null;

        // Для филиала и департамента родитель обязателен
        if ($organizationType->requiresParent()) {
            if ($parentId <= 0) {
                $this->addFlash('error', 'Для филиала и департамента необходимо выбрать родительскую организацию или филиал.');
                return $renderForm($formData);
            }
            $parentOrg = $organizationRepository->find($parentId);
            if (!$parentOrg) {
                $this->addFlash('error', 'Выбранная родительская организация не найдена.');
                return $renderForm($formData);
            }
        }

        // Создаём сущность по типу
        $organization = match ($organizationType) {
            OrganizationType::FILIAL => new Filial(),
            OrganizationType::DEPARTMENT => new Department(),
            OrganizationType::ORGANIZATION => new Organization(),
        };

        $organization->setShortName(trim((string) ($formData['short_name'] ?? '')));
        $organization->setFullName(trim((string) ($formData['full_name'] ?? '')));
        $organization->setDescription(trim((string) ($formData['description'] ?? '')) ?: null);
        $organization->setLegalAddress(trim((string) ($formData['legal_address'] ?? '')) ?: null);
        $organization->setActualAddress(trim((string) ($formData['actual_address'] ?? '')) ?: null);
        $organization->setPhone(trim((string) ($formData['phone'] ?? '')) ?: null);
        $organization->setEmail(trim((string) ($formData['email'] ?? '')) ?: null);
        $organization->setInn(trim((string) ($formData['inn'] ?? '')) ?: null);
        $organization->setKpp(trim((string) ($formData['kpp'] ?? '')) ?: null);
        $organization->setOgrn(trim((string) ($formData['ogrn'] ?? '')) ?: null);
        $organization->setRegistrationOrgan(trim((string) ($formData['registration_organ'] ?? '')) ?: null);
        $organization->setBankName(trim((string) ($formData['bank_name'] ?? '')) ?: null);
        $organization->setBik(trim((string) ($formData['bik'] ?? '')) ?: null);
        $organization->setBankAccount(trim((string) ($formData['bank_account'] ?? '')) ?: null);
        $organization->setTaxType(TaxType::tryFrom(trim((string) ($formData['tax_type'] ?? ''))));
        $regDate = trim((string) ($formData['registration_date'] ?? ''));
        $organization->setRegistrationDate($regDate !== '' ? (\DateTimeImmutable::createFromFormat('Y-m-d', $regDate) ?: null) : null);

        if ($parentId > 0) {
            $parentOrg = $parentOrg ?? $organizationRepository->find($parentId);
            if ($parentOrg instanceof AbstractOrganization) {
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
