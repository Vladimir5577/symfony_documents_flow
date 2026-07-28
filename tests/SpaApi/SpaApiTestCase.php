<?php

declare(strict_types=1);

namespace App\Tests\SpaApi;

use App\Entity\User\Role;
use App\Entity\User\User;
use App\Enum\User\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Базовый харнесс функциональных тестов JWT SPA API (/spa/api/*).
 *
 * В проекте исторически не было функциональных тестов JWT-контуров (LoginControllerTest
 * покрывает только Twig-форму) — этот класс закрывает пробел: фикстура пользователя
 * с ролями + выпуск токена напрямую через lexik JWTTokenManagerInterface (без прохода
 * по /spa/api/login_check в каждом тесте).
 */
abstract class SpaApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;
    }

    /**
     * @param list<UserRole> $roles
     */
    protected function createApiUser(string $login, array $roles = [], string $password = 'password'): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setLogin($login);
        $user->setLastname('Тестов');
        $user->setFirstname('Тест');
        $user->setPassword($hasher->hashPassword($user, $password));

        foreach ($roles as $roleEnum) {
            $user->addRoleEntity($this->findOrCreateRole($roleEnum));
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(User $user): array
    {
        /** @var JWTTokenManagerInterface $jwtManager */
        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwtManager->create($user)];
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    protected function jsonRequest(User $user, string $method, string $uri, ?array $payload = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'],
            content: $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function responseJson(): array
    {
        $content = (string) $this->client->getResponse()->getContent();

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function findOrCreateRole(UserRole $roleEnum): Role
    {
        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => $roleEnum->value]);
        if ($role === null) {
            $role = (new Role())
                ->setRole($roleEnum)
                ->setLabel($roleEnum->getLabel());
            $this->em->persist($role);
        }

        return $role;
    }
}
