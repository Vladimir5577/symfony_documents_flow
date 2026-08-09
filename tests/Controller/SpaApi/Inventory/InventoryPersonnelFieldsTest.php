<?php

declare(strict_types=1);

namespace App\Tests\Controller\SpaApi\Inventory;

use App\Entity\Inventory\InventoryAccess;
use App\Entity\Organization\Organization;
use App\Entity\User\Role;
use App\Entity\User\User;
use App\Enum\User\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Логин на экранах инвентаризации виден только администратору инвентаризации.
 *
 * Проверка именно ответами эндпоинта, а не поиском по коду: роль здесь решает
 * не «пустить или не пустить», а «сколько показать», и убедиться в этом можно
 * только сравнив ответы разным людям. Три роли: рядовой сотрудник (его вообще
 * не пускают), менеджер инвентаризации (видит ФИО, логина нет) и администратор
 * (видит всё).
 */
final class InventoryPersonnelFieldsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    private User $plainUser;
    private User $manager;
    private User $admin;
    private Organization $organization;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        // Физическая уборка: у User включено мягкое удаление, и обычный remove()
        // оставил бы занятыми логины из прошлого прогона.
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM inventory_access');
        $connection->executeStatement('DELETE FROM user_role');
        $connection->executeStatement('DELETE FROM "user"');
        $connection->executeStatement('DELETE FROM organization');
        $this->em->clear();

        $this->organization = new Organization();
        $this->organization->setName('Тестовая организация');
        $this->em->persist($this->organization);

        $this->plainUser = $this->createUser('rank.and.file', 'Рядовой', null);
        $this->manager = $this->createUser('inv.manager', 'Менеджеров', UserRole::ROLE_INVENTORY_MANAGER);
        $this->admin = $this->createUser('inv.admin', 'Админов', UserRole::ROLE_INVENTORY_ADMIN);

        // Менеджеру нужна привязка к организации, иначе его область пуста и
        // справочник ответит отказом ещё до вопроса о полях. Привязка без
        // категории означает «админ организации» (см. InventoryAccess).
        $access = new InventoryAccess();
        $access->setUser($this->manager);
        $access->setOrganization($this->organization);
        $access->setCategory(null);
        $this->em->persist($access);

        $this->em->flush();
    }

    public function testRankAndFileUserIsNotAllowedIntoInventoryReference(): void
    {
        $this->client->loginUser($this->plainUser, 'spa_api');
        $this->client->request(
            'GET',
            '/spa/api/inventory/references/users?organization_id=' . $this->organization->getId(),
        );

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testManagerSeesNamesWithoutLogin(): void
    {
        $this->client->loginUser($this->manager, 'spa_api');
        $users = $this->requestUsers();

        self::assertNotEmpty($users, 'справочник должен вернуть сотрудников организации');
        foreach ($users as $user) {
            self::assertArrayHasKey('login', $user, 'ключ login должен остаться: фронт объявляет его как string | null');
            self::assertNull($user['login'], 'менеджеру инвентаризации логин не показывается');
            self::assertNotSame('-', $user['lastname'], 'фамилия должна остаться на месте');
        }
    }

    public function testAdminSeesLogin(): void
    {
        $this->client->loginUser($this->admin, 'spa_api');
        $users = $this->requestUsers();

        $logins = array_column($users, 'login');
        self::assertContains('inv.manager', $logins, 'администратору инвентаризации логины видны');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function requestUsers(): array
    {
        $this->client->request(
            'GET',
            '/spa/api/inventory/references/users?organization_id=' . $this->organization->getId(),
        );

        $response = $this->client->getResponse();
        self::assertSame(
            200,
            $response->getStatusCode(),
            'справочник должен ответить 200, а ответил: ' . substr((string) $response->getContent(), 0, 300),
        );

        return json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR)['users'] ?? [];
    }

    private function createUser(string $login, string $lastname, ?UserRole $role): User
    {
        $user = new User();
        $user->setLogin($login);
        $user->setLastname($lastname);
        $user->setFirstname('Тест');
        $user->setPassword('not-used-in-this-test');
        $user->setOrganization($this->organization);
        $this->em->persist($user);

        if ($role !== null) {
            $roleEntity = $this->em->getRepository(Role::class)->findOneBy(['name' => $role->value]);
            if ($roleEntity === null) {
                $roleEntity = new Role($role);
                $roleEntity->setLabel($role->getLabel());
                $this->em->persist($roleEntity);
            }
            // Именно addRoleEntity, а не отдельный persist связки: роли берутся
            // из коллекции самого пользователя, и объект в памяти (тот, которым
            // логинится тест) должен о них знать. Связка уезжает в базу
            // каскадом.
            $user->addRoleEntity($roleEntity);
        }

        return $user;
    }
}
