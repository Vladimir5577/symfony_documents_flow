<?php

namespace App\Controller\User;

use App\Entity\User;
use App\Repository\DepartmentDivisionRepository;
use App\Repository\DepartmentRepository;
use App\Repository\OrganizationRepository;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use App\Utils\LoginGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class UserController extends AbstractController
{
    #[Route(path: '/register', name: 'user_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        RoleRepository $roleRepository,
        LoginGenerator $loginGenerator
    ): Response
    {
        $formData = [];
        if ($request->isMethod('POST')) {
            $formData = $request->request->all();
            $request->getSession()->set('register_form_data', $formData);

            if (!$this->isCsrfTokenValid('register', $formData['_csrf_token'] ?? '')) {
                $request->getSession()->set('register_error', 'Неверный CSRF токен.');
                return $this->redirectToRoute('app_register');
            }

            $lastname = trim((string) ($formData['fname-column'] ?? ''));
            $firstname = trim((string) ($formData['lname-column'] ?? ''));
            $plainPassword = (string) ($formData['plain_password'] ?? '');
            $confirmPassword = (string) ($formData['confirm_password'] ?? '');

            if ($lastname === '' || $firstname === '') {
                $request->getSession()->set('register_error', 'Имя и фамилия обязательны.');
                return $this->redirectToRoute('app_register');
            }

            if ($plainPassword !== $confirmPassword) {
                $request->getSession()->set('register_error', 'Пароли не совпадают.');
                return $this->redirectToRoute('app_register');
            }

            $login = $loginGenerator->generateLoginBase($lastname, $firstname);
            if ($login === '') {
                $request->getSession()->set('register_error', 'Не удалось сформировать логин.');
                return $this->redirectToRoute('app_register');
            }

            $user = new User();
            $user->setLogin($login);
            $user->setLastname($lastname ?: null);
            $user->setFirstname($firstname ?: null);
            $user->setPatronymic(trim((string) ($formData['city-column'] ?? '')) ?: null);
            $user->setPhone(trim((string) ($formData['phone-column'] ?? '')) ?: null);
            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));

            // Assign default role ROLE_USER
            $defaultRole = $roleRepository->findOneBy(['name' => 'ROLE_USER']);
            if ($defaultRole) {
                $user->addRoleEntity($defaultRole);
            }

            $entityManager->persist($user);
            $entityManager->flush();

            $request->getSession()->remove('register_form_data');
            $request->getSession()->remove('register_error');
            $this->addFlash('success', 'Регистрация успешна.');

            return $this->redirectToRoute('app_register');
        } else {
            $formData = $request->getSession()->get('register_form_data', []);
            $request->getSession()->remove('register_form_data');
        }

        $error = $request->getSession()->get('register_error');
        $request->getSession()->remove('register_error');

        return $this->render('auth/register.html.twig', [
            'active_tab' => 'register',
            'error' => $error,
            'form_data' => $formData,
        ]);
    }

    #[Route('/users', name: 'app_all_users', methods: ['GET'])]
    public function getAllUsers(Request $request, UserRepository $userRepository): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 10; // Количество пользователей на странице

        $pagination = $userRepository->findPaginated($page, $limit);

        return $this->render('user/all_users.html.twig', [
            'active_tab' => 'all_users',
            'controller_name' => 'UserController',
            'users' => $pagination['users'],
            'pagination' => [
                'current_page' => $pagination['page'],
                'total_pages' => $pagination['totalPages'],
                'total_items' => $pagination['total'],
                'items_per_page' => $pagination['limit'],
            ],
        ]);
    }

    #[Route('/user/{id}', name: 'app_view_user', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function viewUser(int $id, Request $request, UserRepository $userRepository): Response
    {
        // Получаем номер страницы из query параметра для возврата к той же странице списка
        $page = max(1, (int) $request->query->get('page', 1));

        // Загружаем пользователя со всеми связанными данными для избежания N+1
        $user = $userRepository->createQueryBuilder('u')
            ->leftJoin('u.organization', 'org')->addSelect('org')
            ->leftJoin('u.department', 'dept')->addSelect('dept')
            ->leftJoin('u.departmentDivision', 'div')->addSelect('div')
            ->leftJoin('u.boss', 'boss')->addSelect('boss')
            ->leftJoin('u.userRoles', 'ur')->addSelect('ur')
            ->leftJoin('ur.role', 'r')->addSelect('r')
            ->where('u.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$user) {
            throw $this->createNotFoundException('Пользователь не найден');
        }

        return $this->render('user/view_user.html.twig', [
            'active_tab' => 'view_user',
            'user' => $user,
            'page' => $page,
        ]);
    }

    #[Route('/user_edit/{id}', name: 'app_edit_user', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function editUserPage(
        int $id,
        Request $request,
        UserRepository $userRepository,
        OrganizationRepository $organizationRepository,
        DepartmentRepository $departmentRepository,
        DepartmentDivisionRepository $departmentDivisionRepository,
        RoleRepository $roleRepository
    ): Response {
        // Загружаем пользователя со всеми связанными данными для избежания N+1
        $user = $userRepository->createQueryBuilder('u')
            ->leftJoin('u.organization', 'org')->addSelect('org')
            ->leftJoin('u.department', 'dept')->addSelect('dept')
            ->leftJoin('u.departmentDivision', 'div')->addSelect('div')
            ->leftJoin('u.boss', 'boss')->addSelect('boss')
            ->leftJoin('u.userRoles', 'ur')->addSelect('ur')
            ->leftJoin('ur.role', 'r')->addSelect('r')
            ->where('u.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$user) {
            throw $this->createNotFoundException('Пользователь не найден');
        }

        // Получаем списки для выпадающих списков
        $organizations = $organizationRepository->findAll();
        $departments = $departmentRepository->findAll();
        $departmentDivisions = $departmentDivisionRepository->findAll();
        $allUsers = $userRepository->findAll(); // Для выбора начальника
        $roles = $roleRepository->findAll();

        return $this->render('user/edit_user.html.twig', [
            'active_tab' => 'edit_user',
            'user' => $user,
            'organizations' => $organizations,
            'departments' => $departments,
            'departmentDivisions' => $departmentDivisions,
            'allUsers' => $allUsers,
            'roles' => $roles,
        ]);
    }

    #[Route('/user_update', name: 'app_update_user', methods: ['POST'])]
    public function editUser(
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        OrganizationRepository $organizationRepository,
        DepartmentRepository $departmentRepository,
        DepartmentDivisionRepository $departmentDivisionRepository,
        RoleRepository $roleRepository
    ): Response {
        $formData = $request->request->all();

        // Валидация CSRF токена
        if (!$this->isCsrfTokenValid('edit_user', $formData['_csrf_token'] ?? '')) {
            $this->addFlash('error', 'Неверный CSRF токен.');
            return $this->redirectToRoute('app_all_users');
        }

        // Получаем ID пользователя из формы
        $userId = (int) ($formData['user_id'] ?? 0);
        if ($userId === 0) {
            $this->addFlash('error', 'Не указан ID пользователя.');
            return $this->redirectToRoute('app_all_users');
        }

        // Загружаем пользователя со всеми связанными данными
        // Фильтр soft delete автоматически исключает удаленных пользователей
        $user = $userRepository->createQueryBuilder('u')
            ->leftJoin('u.organization', 'org')->addSelect('org')
            ->leftJoin('u.department', 'dept')->addSelect('dept')
            ->leftJoin('u.departmentDivision', 'div')->addSelect('div')
            ->leftJoin('u.boss', 'boss')->addSelect('boss')
            ->leftJoin('u.userRoles', 'ur')->addSelect('ur')
            ->leftJoin('ur.role', 'r')->addSelect('r')
            ->where('u.id = :id')
            ->setParameter('id', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$user) {
            $this->addFlash('error', 'Пользователь не найден.');
            return $this->redirectToRoute('app_all_users');
        }

        // Обновляем основные поля только если они изменились
        $lastname = trim((string) ($formData['lastname'] ?? '')) ?: null;
        if ($user->getLastname() !== $lastname) {
            $user->setLastname($lastname);
        }

        $firstname = trim((string) ($formData['firstname'] ?? '')) ?: null;
        if ($user->getFirstname() !== $firstname) {
            $user->setFirstname($firstname);
        }

        $patronymic = trim((string) ($formData['patronymic'] ?? '')) ?: null;
        if ($user->getPatronymic() !== $patronymic) {
            $user->setPatronymic($patronymic);
        }

        $phone = trim((string) ($formData['phone'] ?? '')) ?: null;
        if ($user->getPhone() !== $phone) {
            $user->setPhone($phone);
        }

        $login = trim((string) ($formData['login'] ?? ''));
        if ($login === '') {
            $this->addFlash('error', 'Логин обязателен.');
            return $this->redirectToRoute('app_edit_user', ['id' => $userId]);
        }
        if ($user->getLogin() !== $login) {
            $user->setLogin($login);
        }

        $isActive = isset($formData['is_active']) && $formData['is_active'] === '1';
        if ($user->isActive() !== $isActive) {
            $user->setIsActive($isActive);
        }

        // Обновляем организацию только если изменилась
        $organizationId = (int) ($formData['organization_id'] ?? 0);
        if ($organizationId > 0) {
            $organization = $organizationRepository->find($organizationId);
            if ($organization && $user->getOrganization()->getId() !== $organization->getId()) {
                $user->setOrganization($organization);
            }
        }

        // Обновляем департамент только если изменился
        $departmentId = (int) ($formData['department_id'] ?? 0);
        if ($departmentId > 0) {
            $department = $departmentRepository->find($departmentId);
            if ($department && $user->getDepartment()->getId() !== $department->getId()) {
                $user->setDepartment($department);
            }
        }

        // Обновляем подразделение только если изменилось
        $departmentDivisionId = (int) ($formData['department_division_id'] ?? 0);
        if ($departmentDivisionId > 0) {
            $departmentDivision = $departmentDivisionRepository->find($departmentDivisionId);
            if ($departmentDivision && $user->getDepartmentDivision()->getId() !== $departmentDivision->getId()) {
                $user->setDepartmentDivision($departmentDivision);
            }
        }

        // Обновляем начальника только если изменился
        $bossId = isset($formData['boss_id']) && $formData['boss_id'] !== '' ? (int) $formData['boss_id'] : null;
        $currentBossId = $user->getBoss()?->getId();
        if ($bossId !== $currentBossId) {
            if ($bossId !== null) {
                if ($bossId === $userId) {
                    $this->addFlash('error', 'Пользователь не может быть начальником сам себе.');
                    return $this->redirectToRoute('app_edit_user', ['id' => $userId]);
                }
                $boss = $userRepository->find($bossId);
                if ($boss) {
                    $user->setBoss($boss);
                }
            } else {
                $user->setBoss(null);
            }
        }

        // Обновляем роли - сравниваем текущие с новыми и обновляем только изменения
        $selectedRoleIds = $formData['roles'] ?? [];
        if (!is_array($selectedRoleIds)) {
            $selectedRoleIds = [];
        }
        $selectedRoleIds = array_map('intval', $selectedRoleIds);

        // Получаем текущие ID ролей
        $currentRoleIds = [];
        foreach ($user->getRolesRel() as $userRole) {
            $role = $userRole->getRole();
            if ($role) {
                $currentRoleIds[] = $role->getId();
            }
        }

        // Сортируем для сравнения
        sort($currentRoleIds);
        sort($selectedRoleIds);

        // Обновляем роли только если они изменились
        if ($currentRoleIds !== $selectedRoleIds) {
            // Удаляем роли, которых нет в новых
            $rolesToRemove = [];
            foreach ($user->getRolesRel() as $userRole) {
                $role = $userRole->getRole();
                if ($role && !in_array($role->getId(), $selectedRoleIds, true)) {
                    $rolesToRemove[] = $userRole;
                }
            }
            foreach ($rolesToRemove as $userRole) {
                $user->getRolesRel()->removeElement($userRole);
                $entityManager->remove($userRole);
            }

            // Добавляем новые роли, которых еще нет
            foreach ($selectedRoleIds as $roleId) {
                if (!in_array($roleId, $currentRoleIds, true)) {
                    $role = $roleRepository->find($roleId);
                    if ($role) {
                        $user->addRoleEntity($role);
                    }
                }
            }
        }

        // Сохраняем изменения
        $entityManager->flush();

        $this->addFlash('success', 'Пользователь успешно обновлен.');

        return $this->redirectToRoute('app_view_user', ['id' => $userId]);
    }
}
