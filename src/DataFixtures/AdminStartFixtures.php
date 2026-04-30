<?php

namespace App\DataFixtures;

use App\Entity\Organization\Organization;
use App\Entity\User\Role;
use App\Entity\User\User;
use App\Enum\UserRole;
use App\Repository\Organization\OrganizationRepository;
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
        $admin->setPassword($this->passwordHasher->hashPassword($admin, '1234'));
        $admin->addRoleEntity($adminRole);
        $manager->persist($admin);

        // Сохраняем ссылку на админа
        $this->addReference('admin_user', $admin);

        // Сначала сохраняем все сущности
        $manager->flush();
    }
}
