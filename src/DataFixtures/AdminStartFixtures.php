<?php

namespace App\DataFixtures;

use App\Entity\Role;
use App\Entity\User;
use App\Enum\UserRole;
use App\Entity\Organization;
use Doctrine\Persistence\ObjectManager;
use App\Repository\OrganizationRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AdminStartFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Создаем админскую организацию
        $adminOrganization = new Organization();
        $adminOrganization->setName(OrganizationRepository::ADMIN_ORGANIZATION_NAME);
        $adminOrganization->setDescription('Организация для системных администраторов');
        $adminOrganization->setAddress('г. Ростов-на-Дону, ул. Административная, д. 1');
        $adminOrganization->setPhone('+7 (863) 000-00-01');
        $adminOrganization->setEmail('admin@system.local');
        $manager->persist($adminOrganization);

        // Сохраняем ссылку на организацию
        $this->addReference('admin_organization', $adminOrganization);

        // Создаем все роли из enum
        $adminRole = null;
        foreach (UserRole::cases() as $userRole) {
            $role = new Role($userRole->value);
            $role->setLabel($userRole->getLabel());
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
        $admin->setOrganization($adminOrganization);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, '1234'));
        $admin->setIsActive(true);
        $admin->addRoleEntity($adminRole);
        $manager->persist($admin);

        // Сохраняем ссылку на админа
        $this->addReference('admin_user', $admin);

        // Сначала сохраняем все сущности
        $manager->flush();
    }
}
