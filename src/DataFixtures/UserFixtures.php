<?php

namespace App\DataFixtures;

use App\Entity\Organization;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\RoleRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    private const PASSWORD = '1234';

    /** Ограничение числа организаций для ускорения загрузки (null = все) */
    private const MAX_ORGANIZATIONS = null;

    private static array $lastnames = [
        'Иванов', 'Петров', 'Сидоров', 'Смирнов', 'Кузнецов', 'Попов', 'Соколов',
        'Лебедев', 'Козлов', 'Новиков', 'Морозов', 'Петухов', 'Волков', 'Соловьев',
        'Васильев', 'Зайцев', 'Павлов', 'Семенов', 'Голубев', 'Виноградов', 'Богданов',
        'Воробьев', 'Федоров', 'Михайлов', 'Белов', 'Тарасов', 'Беляев', 'Комаров',
        'Орлов', 'Киселев', 'Макаров', 'Андреев', 'Ковалев', 'Ильин', 'Гусев', 'Титов',
        'Кузьмин', 'Кудрявцев', 'Баранов', 'Куликов', 'Алексеев', 'Степанов', 'Яковлев',
        'Сорокин', 'Сергеев', 'Романов', 'Захаров', 'Борисов', 'Королев', 'Герасимов',
        'Пономарев', 'Григорьев', 'Лазарев', 'Медведев', 'Ершов', 'Никитин', 'Соболев',
    ];

    private static array $firstnames = [
        'Александр', 'Дмитрий', 'Максим', 'Сергей', 'Андрей', 'Алексей', 'Артем',
        'Илья', 'Кирилл', 'Михаил', 'Никита', 'Матвей', 'Роман', 'Егор', 'Арсений',
        'Иван', 'Денис', 'Евгений', 'Тимур', 'Владислав', 'Игорь', 'Владимир',
        'Павел', 'Руслан', 'Марк', 'Лев', 'Анна', 'Мария', 'Елена', 'Ольга', 'Татьяна',
        'Наталья', 'Екатерина', 'Ирина', 'Светлана', 'Юлия', 'Анастасия', 'Дарья',
        'Валерия', 'Полина', 'Виктория', 'Ксения', 'София', 'Александра', 'Василиса',
        'Вероника', 'Маргарита', 'Диана', 'Алиса', 'Елизавета', 'Арина', 'Милана',
    ];

    private static array $patronymics = [
        'Александрович', 'Дмитриевич', 'Максимович', 'Сергеевич', 'Андреевич',
        'Алексеевич', 'Артемович', 'Ильич', 'Кириллович', 'Михайлович', 'Никитич',
        'Матвеевич', 'Романович', 'Егорович', 'Арсеньевич', 'Иванович', 'Денисович',
        'Евгеньевич', 'Тимурович', 'Владиславович', 'Игоревич', 'Владимирович',
        'Павлович', 'Русланович', 'Маркович', 'Львович', 'Александровна', 'Дмитриевна',
        'Максимовна', 'Сергеевна', 'Андреевна', 'Алексеевна', 'Артемовна', 'Ильинична',
        'Кирилловна', 'Михайловна', 'Никитична', 'Матвеевна', 'Романовна', 'Егоровна',
        'Арсеньевна', 'Ивановна', 'Денисовна', 'Евгеньевна', 'Тимуровна', 'Владиславовна',
        'Игоревна', 'Владимировна', 'Павловна', 'Руслановна', 'Марковна', 'Львовна',
    ];

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RoleRepository $roleRepository
    ) {
    }

    public static function getGroups(): array
    {
        return ['users'];
    }

    public function getDependencies(): array
    {
        return [
            RoleFixtures::class,
            OrganizationFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        $orgIndex = 1;
        while (true) {
            if (self::MAX_ORGANIZATIONS !== null && $orgIndex > self::MAX_ORGANIZATIONS) {
                break;
            }
            $refName = 'organization_' . $orgIndex;
            if (!$this->hasReference($refName, Organization::class)) {
                break;
            }
            $organization = $this->getReference($refName, Organization::class);

            if (self::MAX_ORGANIZATIONS !== null || ($orgIndex - 1) % 10 === 0) {
                echo "UserFixtures: org {$orgIndex}…\n";
            }

            $prefix = 'org' . $orgIndex;

            $director = $this->createOrgUser($organization, $prefix . '_director', 'ROLE_CEO');
            $director->setWorkWithDocuments(true);
            $manager->persist($director);

            $managers = [];
            for ($m = 1; $m <= 2; $m++) {
                $managerUser = $this->createOrgUser($organization, $prefix . '_manager_' . $m, 'ROLE_MANAGER');
                $managerUser->setWorkWithDocuments(true);
                $managerUser->setBoss($director);
                $manager->persist($managerUser);
                $managers[] = $managerUser;
            }

            $userCount = random_int(2, 4);
            for ($u = 1; $u <= $userCount; $u++) {
                $user = $this->createOrgUser($organization, $prefix . '_user_' . $u, 'ROLE_USER');
                $user->setBoss($managers[array_rand($managers)]);
                $manager->persist($user);
            }

            $manager->flush();
            $orgIndex++;
        }

        echo "UserFixtures: done, " . ($orgIndex - 1) . " organizations.\n";
    }

    private function createOrgUser(Organization $organization, string $login, string $roleName): User
    {
        $user = new User();
        $user->setLogin($login);
        $user->setEmail($login . '@example.com');
        $user->setLastname(self::$lastnames[array_rand(self::$lastnames)]);
        $user->setFirstname(self::$firstnames[array_rand(self::$firstnames)]);
        if (random_int(0, 99) < 80) {
            $user->setPatronymic(self::$patronymics[array_rand(self::$patronymics)]);
        }
        $user->setPhone(sprintf(
            '+7(%03d) %03d-%02d-%02d',
            random_int(900, 999),
            random_int(100, 999),
            random_int(10, 99),
            random_int(10, 99)
        ));
        $user->setPassword($this->passwordHasher->hashPassword($user, self::PASSWORD));
        $user->setOrganization($organization);
        $user->setIsActive(true);
        $this->attachRole($user, $roleName);

        return $user;
    }

    private function attachRole(User $user, string $roleName): void
    {
        $role = $this->roleRepository->findOneByName(UserRole::from($roleName));
        if ($role) {
            $user->addRoleEntity($role);
        }
    }
}
