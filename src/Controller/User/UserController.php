<?php

namespace App\Controller\User;

use App\Entity\User;
use App\Entity\Worker;
use App\Enum\UserRole;
use App\Repository\OrganizationRepository;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use App\Repository\WorkerRepository;
use App\Utils\LoginGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class UserController extends AbstractController
{
    #[Route(path: '/register', name: 'user_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        RoleRepository $roleRepository,
        OrganizationRepository $organizationRepository,
        LoginGenerator $loginGenerator,
        ValidatorInterface $validator
    ): Response {
        $currentUser = $this->getUser();
        $isAdmin = $currentUser instanceof User && $this->isGranted('ROLE_ADMIN');

        // Получаем дерево организаций
        $userOrganization = $currentUser instanceof User ? $currentUser->getOrganization() : null;
        $organizationTree = $organizationRepository->getOrganizationTree($isAdmin ? null : $userOrganization);

        // Загружаем организации с дочерними для отображения дерева
        $organizationsWithChildren = [];
        if (!empty($organizationTree)) {
            foreach ($organizationTree as $org) {
                $loadedOrg = $organizationRepository->findWithChildren($org->getId());
                if ($loadedOrg) {
                    $organizationsWithChildren[] = $loadedOrg;
                }
            }
        }

        $roles = $roleRepository->findAllExceptAdmin();

        $initialFormData = [];
        if (!$isAdmin && $userOrganization) {
            $initialFormData['organization_id'] = $userOrganization->getId();
        }

        $renderForm = function (array $formData = []) use ($isAdmin, $organizationsWithChildren, $roles, $initialFormData): Response {
            $data = $formData !== [] ? $formData : $initialFormData;
            return $this->render('user/register.html.twig', [
                'active_tab' => 'register',
                'form_data' => $data,
                'is_admin' => $isAdmin,
                'organizations' => $organizationsWithChildren,
                'roles' => $roles,
            ]);
        };

        if (!$request->isMethod('POST')) {
            return $renderForm([]);
        }

        $formData = $request->request->all();

        if (!$this->isCsrfTokenValid('register', $formData['_csrf_token'] ?? '')) {
            $this->addFlash('error', 'Неверный CSRF токен.');
            return $this->redirectToRoute('user_register');
        }

        if (!$currentUser instanceof User) {
            $this->addFlash('error', 'Необходимо войти в систему для регистрации пользователя.');
            return $this->redirectToRoute('user_register');
        }

        // Получаем выбранную организацию
        $organizationId = (int) ($formData['organization_id'] ?? 0);
        if ($organizationId <= 0) {
            $this->addFlash('error', 'Необходимо выбрать организацию.');
            return $renderForm($formData);
        }

        $organization = $organizationRepository->find($organizationId);
        if (!$organization) {
            $this->addFlash('error', 'Организация не найдена.');
            return $renderForm($formData);
        }

        // Проверяем права доступа к выбранной организации
        if (!$isAdmin) {
            $userOrganization = $currentUser->getOrganization();
            if (!$userOrganization) {
                $this->addFlash('error', 'Не удалось определить организацию. Обратитесь к администратору.');
                return $this->redirectToRoute('user_register');
            }

            // Разрешена организация пользователя или любая дочерняя к ней
            $isValid = $organization->getId() === $userOrganization->getId();
            if (!$isValid) {
                $checkOrg = $organization;
                while ($checkOrg->getParent() !== null) {
                    $checkOrg = $checkOrg->getParent();
                    if ($checkOrg->getId() === $userOrganization->getId()) {
                        $isValid = true;
                        break;
                    }
                }
            }

            if (!$isValid) {
                $this->addFlash('error', 'Вы не можете выбрать организацию вне вашего дерева организаций.');
                return $renderForm($formData);
            }
        }

        $plainPassword = (string) ($formData['plain_password'] ?? '');
        $confirmPassword = (string) ($formData['confirm_password'] ?? '');
        if ($plainPassword !== $confirmPassword) {
            $this->addFlash('error', 'Пароли не совпадают.');
            return $renderForm($formData);
        }

        $lastname = trim((string) ($formData['fname-column'] ?? ''));
        $firstname = trim((string) ($formData['lname-column'] ?? ''));
        $login = $loginGenerator->generateLoginBase($lastname, $firstname);
        if ($login === '') {
            $this->addFlash('error', 'Не удалось сформировать логин.');
            return $renderForm($formData);
        }

        $user = new User();
        $user->setLogin($login);
        $user->setLastname($lastname !== '' ? $lastname : null);
        $user->setFirstname($firstname !== '' ? $firstname : null);
        $user->setPatronymic(trim((string) ($formData['city-column'] ?? '')) ?: null);
        $user->setPhone(trim((string) ($formData['phone-column'] ?? '')) ?: null);
        $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
        $user->setOrganization($organization);
        $user->setCreatedBy($currentUser);

        // Обрабатываем выбранную роль
        $selectedRoleId = isset($formData['role']) && $formData['role'] !== '' ? (int) $formData['role'] : null;

        if ($selectedRoleId !== null && $selectedRoleId > 0) {
            $role = $roleRepository->find($selectedRoleId);
            if ($role && $role->getRole() !== UserRole::ROLE_ADMIN) {
                $user->addRoleEntity($role);
            }
        }

        // Если роль не выбрана, добавляем ROLE_USER по умолчанию
        if ($selectedRoleId === null || $selectedRoleId === 0) {
            $defaultRole = $roleRepository->findOneByName(UserRole::ROLE_USER);
            if ($defaultRole) {
                $user->addRoleEntity($defaultRole);
            }
        }

        $errors = $validator->validate($user);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error->getMessage());
            }
            return $renderForm($formData);
        }

        $entityManager->persist($user);
        $entityManager->flush();

        $profession = trim((string) ($formData['profession-column'] ?? ''));
        if ($profession !== '') {
            $worker = new Worker();
            $worker->setUserId($user->getId());
            $worker->setProfession($profession);
            $description = trim((string) ($formData['description-column'] ?? ''));
            if ($description !== '') {
                $worker->setDescription($description);
            }
            $entityManager->persist($worker);
            $entityManager->flush();
        }

        $this->addFlash('success', 'Регистрация успешна.');
        return $this->redirectToRoute('app_view_user', ['id' => $user->getId()]);
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
    public function viewUser(int $id, Request $request, UserRepository $userRepository, WorkerRepository $workerRepository): Response
    {
        // Получаем номер страницы из query параметра для возврата к той же странице списка
        $page = max(1, (int) $request->query->get('page', 1));

        // Загружаем пользователя со всеми связанными данными для избежания N+1
        $user = $userRepository->createQueryBuilder('u')
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

        // Загружаем Worker для пользователя, если он существует
        $worker = $workerRepository->findOneBy(['user_id' => $user->getId()]);

        return $this->render('user/view_user.html.twig', [
            'active_tab' => 'view_user',
            'user' => $user,
            'worker' => $worker,
            'page' => $page,
        ]);
    }

    #[Route('/user_edit/{id}', name: 'app_edit_user', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function editUserPage(
        int $id,
        Request $request,
        UserRepository $userRepository,
        OrganizationRepository $organizationRepository,
        RoleRepository $roleRepository,
        WorkerRepository $workerRepository
    ): Response {
        $currentUser = $this->getUser();
        $isAdmin = $currentUser instanceof User && $this->isGranted('ROLE_ADMIN');

        // Загружаем пользователя со всеми связанными данными для избежания N+1
        $user = $userRepository->createQueryBuilder('u')
            ->leftJoin('u.organization', 'org')->addSelect('org')
            ->leftJoin('u.userRoles', 'ur')->addSelect('ur')
            ->leftJoin('ur.role', 'r')->addSelect('r')
            ->where('u.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$user) {
            throw $this->createNotFoundException('Пользователь не найден');
        }

        // Получаем Worker для пользователя, если он существует
        $worker = $workerRepository->findOneBy(['user_id' => $user->getId()]);

        // Получаем дерево организаций
        $currentUserOrg = $currentUser instanceof User ? $currentUser->getOrganization() : null;
        $organizationTree = $organizationRepository->getOrganizationTree($isAdmin ? null : $currentUserOrg);

        // Загружаем организации с дочерними для отображения дерева
        $organizationsWithChildren = [];
        if (!empty($organizationTree)) {
            foreach ($organizationTree as $org) {
                $loadedOrg = $organizationRepository->findWithChildren($org->getId());
                if ($loadedOrg) {
                    $organizationsWithChildren[] = $loadedOrg;
                }
            }
        }

        $roles = $roleRepository->findAllExceptAdmin();

        // Получаем выбранную роль пользователя (первую, если их несколько)
        $selectedRoleId = null;
        foreach ($user->getRolesRel() as $userRole) {
            $role = $userRole->getRole();
            if ($role && $role->getRole() !== UserRole::ROLE_ADMIN) {
                $selectedRoleId = $role->getId();
                break;
            }
        }

        return $this->render('user/edit_user.html.twig', [
            'active_tab' => 'edit_user',
            'user' => $user,
            'worker' => $worker,
            'is_admin' => $isAdmin,
            'organizations' => $organizationsWithChildren,
            'roles' => $roles,
            'selected_role_id' => $selectedRoleId,
        ]);
    }

    #[Route('/user_update', name: 'app_update_user', methods: ['POST'])]
    public function editUser(
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        OrganizationRepository $organizationRepository,
        RoleRepository $roleRepository,
        WorkerRepository $workerRepository,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $currentUser = $this->getUser();
        $isAdmin = $currentUser instanceof User && $this->isGranted('ROLE_ADMIN');
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

        // Обновляем основные поля в формате формы регистрации
        $lastname = trim((string) ($formData['fname-column'] ?? '')) ?: null;
        if ($user->getLastname() !== $lastname) {
            $user->setLastname($lastname);
        }

        $firstname = trim((string) ($formData['lname-column'] ?? '')) ?: null;
        if ($user->getFirstname() !== $firstname) {
            $user->setFirstname($firstname);
        }

        $patronymic = trim((string) ($formData['city-column'] ?? '')) ?: null;
        if ($user->getPatronymic() !== $patronymic) {
            $user->setPatronymic($patronymic);
        }

        $phone = trim((string) ($formData['phone-column'] ?? '')) ?: null;
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

        // Обновляем пароль, если он указан
        $plainPassword = trim((string) ($formData['plain_password'] ?? ''));
        $confirmPassword = trim((string) ($formData['confirm_password'] ?? ''));
        if ($plainPassword !== '') {
            if ($plainPassword !== $confirmPassword) {
                $this->addFlash('error', 'Пароли не совпадают.');
                return $this->redirectToRoute('app_edit_user', ['id' => $userId]);
            }
            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
        }

        // Обновляем организацию
        $organizationId = (int) ($formData['organization_id'] ?? 0);
        if ($organizationId <= 0) {
            $this->addFlash('error', 'Необходимо выбрать организацию.');
            return $this->redirectToRoute('app_edit_user', ['id' => $userId]);
        }

        $organization = $organizationRepository->find($organizationId);
        if (!$organization) {
            $this->addFlash('error', 'Организация не найдена.');
            return $this->redirectToRoute('app_edit_user', ['id' => $userId]);
        }

        // Проверяем права доступа к выбранной организации
        if (!$isAdmin) {
            $currentUserOrg = $currentUser instanceof User ? $currentUser->getOrganization() : null;
            if (!$currentUserOrg) {
                $this->addFlash('error', 'Не удалось определить организацию. Обратитесь к администратору.');
                return $this->redirectToRoute('app_edit_user', ['id' => $userId]);
            }

            // Находим корневую организацию пользователя
            $userRootOrg = $currentUserOrg;
            while ($userRootOrg->getParent() !== null) {
                $userRootOrg = $userRootOrg->getParent();
            }

            // Проверяем, что выбранная организация принадлежит дереву пользователя
            $isValid = false;
            if ($organization->getId() === $userRootOrg->getId()) {
                $isValid = true;
            } else {
                // Проверяем, является ли выбранная организация дочерней в дереве пользователя
                $checkOrg = $organization;
                while ($checkOrg->getParent() !== null) {
                    $checkOrg = $checkOrg->getParent();
                    if ($checkOrg->getId() === $userRootOrg->getId()) {
                        $isValid = true;
                        break;
                    }
                }
            }

            if (!$isValid) {
                $this->addFlash('error', 'Вы не можете выбрать организацию вне вашего дерева организаций.');
                return $this->redirectToRoute('app_edit_user', ['id' => $userId]);
            }
        }

        // Обновляем организацию только если изменилась
        if ($user->getOrganization()->getId() !== $organization->getId()) {
            $user->setOrganization($organization);
        }

        // Обновляем роль (radio button, одна роль)
        $selectedRoleId = isset($formData['role']) && $formData['role'] !== '' ? (int) $formData['role'] : null;

        // Получаем текущую роль (не админскую)
        $currentRoleId = null;
        foreach ($user->getRolesRel() as $userRole) {
            $role = $userRole->getRole();
            if ($role && $role->getRole() !== UserRole::ROLE_ADMIN) {
                $currentRoleId = $role->getId();
                break;
            }
        }

        // Обновляем роль только если она изменилась
        if ($selectedRoleId !== $currentRoleId) {
            // Удаляем все роли кроме админской
            $rolesToRemove = [];
            foreach ($user->getRolesRel() as $userRole) {
                $role = $userRole->getRole();
                if ($role && $role->getRole() !== UserRole::ROLE_ADMIN) {
                    $rolesToRemove[] = $userRole;
                }
            }
            foreach ($rolesToRemove as $userRole) {
                $user->getRolesRel()->removeElement($userRole);
                $entityManager->remove($userRole);
            }

            // Добавляем новую роль, если она выбрана
            if ($selectedRoleId !== null && $selectedRoleId > 0) {
                $role = $roleRepository->find($selectedRoleId);
                if ($role && $role->getRole() !== UserRole::ROLE_ADMIN) {
                    $user->addRoleEntity($role);
                }
            } else {
                // Если роль не выбрана, добавляем ROLE_USER по умолчанию
                $defaultRole = $roleRepository->findOneByName(UserRole::ROLE_USER);
                if ($defaultRole) {
                    $user->addRoleEntity($defaultRole);
                }
            }
        }

        // Устанавливаем updated_by текущим залогиненным пользователем
        if ($currentUser instanceof User) {
            $user->setUpdatedBy($currentUser);
        }

        // Сохраняем изменения пользователя
        $entityManager->flush();

        // Обновляем или создаем Worker
        $profession = trim((string) ($formData['profession-column'] ?? ''));
        $worker = $workerRepository->findOneBy(['user_id' => $user->getId()]);

        if ($profession !== '') {
            if (!$worker) {
                $worker = new Worker();
                $worker->setUserId($user->getId());
            }
            $worker->setProfession($profession);
            $description = trim((string) ($formData['description-column'] ?? ''));
            $worker->setDescription($description !== '' ? $description : null);
            $entityManager->persist($worker);
        } elseif ($worker) {
            // Если профессия пустая, удаляем Worker
            $entityManager->remove($worker);
        }

        $entityManager->flush();

        $this->addFlash('success', 'Пользователь успешно обновлен.');

        return $this->redirectToRoute('app_view_user', ['id' => $userId]);
    }

    #[Route('/user_delete/{id}', name: 'app_delete_user', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteUser(int $id, Request $request, UserRepository $userRepository, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('delete_user_' . $id, $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Неверный CSRF токен.');
            return $this->redirectToRoute('app_all_users');
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_all_users');
        }
        if ($id === $currentUser->getId()) {
            $this->addFlash('error', 'Нельзя удалить самого себя.');
            return $this->redirectToRoute('app_view_user', ['id' => $id]);
        }

        $user = $userRepository->find($id);
        if (!$user || $user->isDeleted()) {
            throw $this->createNotFoundException('Пользователь не найден');
        }

        $user->setDeletedAt(new \DateTimeImmutable());
        $entityManager->flush();

        $this->addFlash('success', 'Пользователь удалён.');
        return $this->redirectToRoute('app_all_users');
    }
}
