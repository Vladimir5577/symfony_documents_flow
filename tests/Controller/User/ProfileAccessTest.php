<?php

declare(strict_types=1);

namespace App\Tests\Controller\User;

use App\Controller\User\UserController;
use App\Entity\Organization\AbstractOrganization;
use App\Entity\Organization\Organization;
use App\Entity\User\Role;
use App\Entity\User\User;
use App\Repository\Organization\OrganizationRepository;
use App\Repository\User\RoleRepository;
use App\Repository\User\UserRepository;
use App\Repository\User\WorkerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

/** Контроллер и security берутся из ядра; репозитории не обращаются к БД. */
final class ProfileAccessTest extends KernelTestCase
{
    private UserController $controller;
    private UserRepository&MockObject $users;
    private EntityManagerInterface&MockObject $em;
    private UserPasswordHasherInterface $hasher;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->controller = $container->get(UserController::class);
        $this->hasher = $container->get('security.user_password_hasher');
        $this->users = $this->createMock(UserRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
    }

    public static function protectedActions(): iterable
    {
        yield 'форма профиля' => ['editUserPage'];
        yield 'изменение профиля' => ['editUser'];
        yield 'форма аватара' => ['editUserPhoto'];
        yield 'изменение аватара' => ['updateUserPhoto'];
    }

    #[DataProvider('protectedActions')]
    public function testAnotherUserIsDeniedBeforeDataIsLoaded(string $action): void
    {
        $this->login($this->user(1));
        $request = $this->request(['user_id' => 2, 'plain_password' => 'hijacked', 'confirm_password' => 'hijacked']);
        $this->users->expects(self::never())->method('find');
        $this->users->expects(self::never())->method('createQueryBuilder');
        $this->em->expects(self::never())->method('flush');

        $this->expectException(AccessDeniedException::class);
        match ($action) {
            'editUserPage' => $this->controller->editUserPage(2, $request, $this->users,
                $this->createStub(OrganizationRepository::class), $this->createStub(RoleRepository::class), $this->createStub(WorkerRepository::class)),
            'editUser' => $this->update($request),
            'editUserPhoto' => $this->controller->editUserPhoto(2, $this->users),
            'updateUserPhoto' => $this->controller->updateUserPhoto(2, $request, $this->users, $this->em),
        };
    }

    public static function profileViewers(): iterable
    {
        foreach ([false, true] as $hasPhoto) {
            yield 'self, photo=' . (int) $hasPhoto => [1, false, $hasPhoto, true];
            yield 'admin, photo=' . (int) $hasPhoto => [2, true, $hasPhoto, true];
            yield 'manager viewing another user, photo=' . (int) $hasPhoto => [2, false, $hasPhoto, false];
        }
    }

    #[DataProvider('profileViewers')]
    public function testProfileEditButtonsAreVisibleOnlyToSelfOrAdmin(int $viewerId, bool $admin, bool $hasPhoto, bool $canEdit): void
    {
        $viewer = $this->user($viewerId, $admin);
        if (!$admin && $viewerId !== 1) {
            $viewer->addRoleEntity(new Role('ROLE_MANAGER'));
        }
        $this->login($viewer);
        $this->request([]);
        $target = $this->user(1);
        if ($hasPhoto) {
            $target->setAvatarName('avatar.jpg');
        }
        $twig = self::getContainer()->get(Environment::class);
        $context = ['user' => $target, 'worker' => null, 'avatar_preview_url' => '/avatar.jpg'];
        $modal = $twig->render('user/partials/_modal_view_user_content.html.twig', $context);
        $page = $twig->load('user/view_user.html.twig')->renderBlock('main', $context);
        $router = self::getContainer()->get('router');
        $editLink = 'href="' . $router->generate('app_edit_user', ['id' => 1]) . '"';
        $photoLink = 'href="' . $router->generate('app_edit_user_photo', ['id' => 1]) . '"';

        self::assertSame($canEdit, str_contains($modal, $editLink));
        self::assertSame($canEdit, str_contains($page, $editLink));
        self::assertSame($canEdit, str_contains($page, $photoLink));
        self::assertSame($admin && $viewerId !== 1, str_contains($page, '<form id="delete-user-form"'));
        self::assertStringContainsString('href="' . $router->generate('app_view_user', ['id' => 1]) . '"', $modal);
        self::assertSame($hasPhoto, str_contains($page, 'data-image-url="'));
    }

    public static function invalidCurrentPasswords(): iterable
    {
        yield 'не передан' => [null, false];
        yield 'пустой' => ['', false];
        yield 'неверный' => ['wrong', false];
        yield 'администратор меняет свой' => ['wrong', true];
    }

    #[DataProvider('invalidCurrentPasswords')]
    public function testSelfPasswordChangeRejectsInvalidCurrentPasswordBeforeAnyMutation(?string $currentPassword, bool $admin): void
    {
        $user = $this->user(1, $admin);
        $oldHash = $user->getPassword();
        $this->login($user);
        $this->returnUser($user);
        $this->em->expects(self::never())->method('flush');

        $data = ['user_id' => 1, 'login' => 'changed', 'phone-column' => '+7 (123) 123 12 12',
            'plain_password' => 'new-password', 'confirm_password' => 'new-password'];
        if ($currentPassword !== null) {
            $data['current_password'] = $currentPassword;
        }
        $response = $this->update($this->request($data));

        self::assertTrue($response->isRedirect('/user_edit/1'));
        self::assertSame('user1', $user->getLogin());
        self::assertNull($user->getPhone());
        self::assertSame($oldHash, $user->getPassword());
    }

    public function testSelfCanChangePasswordWithCurrentPassword(): void
    {
        $user = $this->user(1);
        $this->login($user);
        $this->returnUser($user);
        $this->em->expects(self::exactly(2))->method('flush');

        $response = $this->update($this->request(['user_id' => 1, 'login' => 'changed',
            'current_password' => ' old-password ', 'plain_password' => 'new-password', 'confirm_password' => 'new-password']));

        self::assertTrue($response->isRedirect('/user/1'));
        self::assertSame('changed', $user->getLogin());
        self::assertTrue($this->hasher->isPasswordValid($user, 'new-password'));
        self::assertFalse($this->hasher->isPasswordValid($user, ' old-password '));
    }

    public function testSelfCanEditProfileWithoutChangingPassword(): void
    {
        $user = $this->user(1);
        $oldHash = $user->getPassword();
        $this->login($user);
        $this->returnUser($user);
        $this->em->expects(self::exactly(2))->method('flush');

        $response = $this->update($this->request(['user_id' => 1, 'login' => 'changed']));

        self::assertTrue($response->isRedirect('/user/1'));
        self::assertSame('changed', $user->getLogin());
        self::assertSame($oldHash, $user->getPassword());
    }

    public function testAdminCanResetAnotherUsersPasswordWithoutCurrentPassword(): void
    {
        $this->login($this->user(1, true));
        $target = $this->user(2);
        $organization = new Organization();
        (new \ReflectionProperty(AbstractOrganization::class, 'id'))->setValue($organization, 1);
        $target->setOrganization($organization);
        $organizations = $this->createStub(OrganizationRepository::class);
        $organizations->method('find')->willReturn($organization);
        $this->returnUser($target);
        $this->em->expects(self::exactly(2))->method('flush');

        $response = $this->update($this->request(['user_id' => 2, 'login' => 'user2', 'organization_id' => 1,
            'plain_password' => 'reset-password', 'confirm_password' => 'reset-password']), $organizations);

        self::assertTrue($response->isRedirect('/user/2'));
        self::assertTrue($this->hasher->isPasswordValid($target, 'reset-password'));
    }

    private function user(int $id, bool $admin = false): User
    {
        $user = (new User())->setLogin('user' . $id);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        $user->setPassword($this->hasher->hashPassword($user, ' old-password '));
        if ($admin) {
            $user->addRoleEntity(new Role('ROLE_ADMIN'));
        }

        return $user;
    }

    private function login(User $user): void
    {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function request(array $data): Request
    {
        $request = Request::create('/user_update', 'POST', $data);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);
        $request->request->set('_csrf_token', self::getContainer()->get('security.csrf.token_manager')->getToken('edit_user')->getValue());

        return $request;
    }

    private function returnUser(User $user): void
    {
        $query = $this->createStub(Query::class);
        $query->method('getOneOrNullResult')->willReturn($user);
        $builder = $this->createStub(QueryBuilder::class);
        foreach (['leftJoin', 'addSelect', 'where', 'setParameter'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $builder->method('getQuery')->willReturn($query);
        $this->users->method('createQueryBuilder')->willReturn($builder);
    }

    private function update(Request $request, ?OrganizationRepository $organizations = null): Response
    {
        return $this->controller->editUser($request, $this->em, $this->users,
            $organizations ?? $this->createStub(OrganizationRepository::class),
            $this->createStub(RoleRepository::class), $this->createStub(WorkerRepository::class), $this->hasher);
    }
}
