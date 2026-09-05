<?php

namespace App\DataFixtures;

use App\Entity\User\Role;
use App\Entity\User\User;
use App\Enum\User\UserRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AdminStartFixtures extends Fixture implements FixtureGroupInterface
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {
    }

    public static function getGroups(): array
    {
        return ['admin'];
    }

    public function load(ObjectManager $manager): void
    {
        // Создаем все роли из enum
        $adminRole = null;
        foreach (UserRole::cases() as $index => $userRole) {
            $role = new Role($userRole);
            $role->setLabel($userRole->getLabel());
            $role->setSortOrder($index);
            $manager->persist($role);

            // Сохраняем ссылку на роль админа
            if ($userRole === UserRole::ROLE_ADMIN) {
                $adminRole = $role;
                $this->addReference('admin_role', $role);
            }
        }

        // Создаем админа
        $admin = new User();
        $admin->setLogin('admin');
        $admin->setLastname('Админ');
        $admin->setFirstname('Системный');
        $admin->setEmail('admin@admin.com');
        // Админ без привязки к конкретной организации
        $admin->setOrganization(null);
        // BE-04: пароль admin — из FIXTURE_ADMIN_PASSWORD, иначе случайный с выводом
        // в консоль. Зашитый «1234» оставался на dev-стенде с APP_ENV=dev.
        $password = (string) ($_ENV['FIXTURE_ADMIN_PASSWORD'] ?? $_SERVER['FIXTURE_ADMIN_PASSWORD'] ?? '');
        if ($password === '') {
            $password = bin2hex(random_bytes(8));
            fwrite(STDERR, sprintf("[fixtures] Пароль пользователя admin: %s (задайте FIXTURE_ADMIN_PASSWORD, чтобы выбрать свой)\n", $password));
        }
        $admin->setPassword($this->passwordHasher->hashPassword($admin, $password));
        $admin->addRoleEntity($adminRole);
        $manager->persist($admin);

        // Сохраняем ссылку на админа
        $this->addReference('admin_user', $admin);

        // Сначала сохраняем все сущности
        $manager->flush();
    }
}
