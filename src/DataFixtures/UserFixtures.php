<?php

namespace App\DataFixtures;

use App\Entity\Organization\AbstractOrganization;
use App\Entity\Organization\Department;
use App\Entity\Organization\Filial;
use App\Entity\Organization\Organization;
use App\Entity\User\User;
use App\Enum\UserRole;
use App\Repository\User\RoleRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    private const PASSWORD = '1234';

    /** Работников в департаменте: от N до M */
    private const WORKERS_PER_DEPARTMENT_MIN = 5;
    private const WORKERS_PER_DEPARTMENT_MAX = 15;

    /** Flush + clear каждые N департаментов */
    private const DEPARTMENT_CHUNK_SIZE = 5;

    private static array $lastnames = [
        'Иванов', 'Петров', 'Сидоров', 'Смирнов', 'Кузнецов', 'Попов', 'Соколов',
        'Лебедев', 'Козлов', 'Новиков', 'Морозов', 'Петухов', 'Волков', 'Соловьев',
        'Васильев', 'Зайцев', 'Павлов', 'Семенов', 'Голубев', 'Виноградов', 'Богданов',
        'Воробьев', 'Федоров', 'Михайлов', 'Белов', 'Тарасов', 'Беляев', 'Комаров',
        'Орлов', 'Киселев', 'Макаров', 'Андреев', 'Ковалев', 'Ильин', 'Гусев', 'Титов',
    ];

    private static array $firstnames = [
        'Александр', 'Дмитрий', 'Максим', 'Сергей', 'Андрей', 'Алексей', 'Артем',
        'Илья', 'Кирилл', 'Михаил', 'Никита', 'Иван', 'Анна', 'Мария', 'Елена', 'Ольга',
    ];

    private static array $patronymics = [
        'Александрович', 'Дмитриевич', 'Максимович', 'Сергеевич', 'Андреевич',
        'Иванович', 'Михайлович', 'Александровна', 'Дмитриевна', 'Сергеевна', 'Ивановна',
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
        $this->log('UserFixtures: начало загрузки пользователей…');

        // Организации: 1 директор + 2 работника
        $this->log('Организации (1 директор + 2 работника):');
        for ($orgIndex = 1; $orgIndex <= 2; $orgIndex++) {
            $organization = $this->getReference('organization_' . $orgIndex, Organization::class);
            $prefix = 'org' . $orgIndex;
            $director = $this->createUnitUser($organization, $prefix . '_director', 'ROLE_MANAGER', null);
            $director->setWorkWithDocuments(true);
            $manager->persist($director);
            for ($m = 1; $m <= 2; $m++) {
                $u = $this->createUnitUser($organization, $prefix . '_user_' . $m, 'ROLE_MANAGER', $director);
                $u->setWorkWithDocuments(true);
                $manager->persist($u);
            }
            $manager->flush();
            $manager->clear();
            $this->log("  org{$orgIndex}: 3 пользователя — flush");
        }
        $this->log('');

        // Филиалы: 1 директор + 2 работника на каждый
        $this->log('Филиалы (1 директор + 2 работника на каждый):');
        $filialIndex = 1;
        while ($this->hasReference('filial_' . $filialIndex, Filial::class)) {
            $filial = $this->getReference('filial_' . $filialIndex, Filial::class);
            $prefix = 'filial' . $filialIndex;
            $director = $this->createUnitUser($filial, $prefix . '_director', 'ROLE_MANAGER', null);
            $director->setWorkWithDocuments(true);
            $manager->persist($director);
            for ($m = 1; $m <= 2; $m++) {
                $u = $this->createUnitUser($filial, $prefix . '_user_' . $m, 'ROLE_MANAGER', $director);
                $u->setWorkWithDocuments(true);
                $manager->persist($u);
            }
            $manager->flush();
            $manager->clear();
            $this->log("  filial{$filialIndex}: 3 пользователя — flush");
            $filialIndex++;
        }
        $filialCount = $filialIndex - 1;
        $this->log('');

        // Департаменты: 5–15 работников на каждый, чанками
        $this->log('Департаменты (5–15 работников на каждый, чанки по ' . self::DEPARTMENT_CHUNK_SIZE . '):');
        $deptIndex = 1;
        $deptInChunk = 0;
        $totalDeptUsers = 0;
        while ($this->hasReference('department_' . $deptIndex, Department::class)) {
            $department = $this->getReference('department_' . $deptIndex, Department::class);
            $count = random_int(self::WORKERS_PER_DEPARTMENT_MIN, self::WORKERS_PER_DEPARTMENT_MAX);
            $totalDeptUsers += $count;
            $prefix = 'dept' . $deptIndex;
            for ($i = 1; $i <= $count; $i++) {
                $u = $this->createUnitUser($department, $prefix . '_' . $i, 'ROLE_USER', null);
                $manager->persist($u);
            }
            $deptInChunk++;
            $deptIndex++;

            if ($deptInChunk >= self::DEPARTMENT_CHUNK_SIZE) {
                $manager->flush();
                $manager->clear();
                $from = $deptIndex - self::DEPARTMENT_CHUNK_SIZE;
                $to = $deptIndex - 1;
                $this->log("  департаменты {$from}–{$to} — flush");
                $deptInChunk = 0;
            }
        }
        if ($deptInChunk > 0) {
            $manager->flush();
            $manager->clear();
            $this->log("  департаменты " . ($deptIndex - $deptInChunk) . "–" . ($deptIndex - 1) . " — flush (остаток)");
        }
        $deptCount = $deptIndex - 1;
        $this->log('');

        $totalUsers = 6 + $filialCount * 3 + $totalDeptUsers;
        $this->log("UserFixtures: готово. Организаций: 2, филиалов: {$filialCount}, департаментов: {$deptCount}, пользователей: {$totalUsers}");
    }

    private function log(string $message): void
    {
        echo $message . \PHP_EOL;
    }

    private function createUnitUser(AbstractOrganization $unit, string $login, string $roleName, ?User $boss): User
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
        $user->setOrganization($unit);
        if ($boss) {
            $user->setBoss($boss);
        }
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
