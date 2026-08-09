<?php

namespace App\Tests;

use App\Entity\User\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class LoginControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');

        // Чистим пользователей физически.
        //
        // User помечен Gedmo SoftDeleteable (hardDelete: false): $em->remove()
        // не удаляет строку, а проставляет deleted_at. Логин остаётся занятым,
        // а фильтр softdeleteable прячет строку от findAll() — поэтому второй
        // прогон теста подряд падал на уникальном индексе uniq_identifier_login,
        // хотя «пользователей нет». Тестовой базе нужно именно физическое
        // удаление.
        $em->getConnection()->executeStatement('DELETE FROM "user"');
        $em->clear();

        // Create a User fixture
        /** @var UserPasswordHasherInterface $passwordHasher */
        $passwordHasher = $container->get('security.user_password_hasher');

        // Фикстура из скелета Symfony заполняла только email и пароль, а
        // сущность User с тех пор обзавелась обязательными полями: вставка
        // падала на NOT NULL для lastname. Плюс провайдер ищет пользователя по
        // login (см. security.yaml), а форма ниже отправляет туда
        // email@example.com — значит login должен совпадать с этой строкой,
        // иначе успешный вход не проверяется вовсе.
        $user = (new User())->setEmail('email@example.com');
        $user->setLogin('email@example.com');
        $user->setLastname('Тестов');
        $user->setFirstname('Тест');
        $user->setPassword($passwordHasher->hashPassword($user, 'password'));

        $em->persist($user);
        $em->flush();
    }

    public function testLogin(): void
    {
        // Denied - Can't login with invalid email address.
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Войти', [
            '_username' => 'doesNotExist@example.com',
            '_password' => 'password',
        ]);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();

        // Ensure we do not reveal if the user exists or not.
        self::assertSelectorTextContains('.alert-danger', 'Неправильные логин или пароль');

        // Denied - Can't login with invalid password.
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Войти', [
            '_username' => 'email@example.com',
            '_password' => 'bad-password',
        ]);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();

        // Ensure we do not reveal the user exists but the password is wrong.
        self::assertSelectorTextContains('.alert-danger', 'Неправильные логин или пароль');

        // Success - Login with valid credentials is allowed.
        $this->client->submitForm('Войти', [
            '_username' => 'email@example.com',
            '_password' => 'password',
        ]);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();

        self::assertSelectorNotExists('.alert-danger');
    }
}
