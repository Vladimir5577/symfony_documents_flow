<?php

namespace App\DataFixtures;

use App\Entity\User;
use App\Repository\RoleRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
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
        return [RoleFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $admin = new User();
        $admin->setLogin('admin.0001');
        $admin->setLastname('Админ');
        $admin->setFirstname('Системный');
        $admin->setPassword($this->passwordHasher->hashPassword($admin, '1234'));
        $this->attachRole($admin, 'ROLE_ADMIN');
        $manager->persist($admin);

        $hr = new User();
        $hr->setLogin('hr.0001');
        $hr->setLastname('Кадровик');
        $hr->setFirstname('Главный');
        $hr->setPassword($this->passwordHasher->hashPassword($hr, '1234'));
        $this->attachRole($hr, 'ROLE_HR');
        $manager->persist($hr);

        $editor = new User();
        $editor->setLogin('editor.0001');
        $editor->setLastname('Редактор');
        $editor->setFirstname('Главный');
        $editor->setPassword($this->passwordHasher->hashPassword($editor, '1234'));
        $this->attachRole($editor, 'ROLE_EDITOR');
        $manager->persist($editor);

        $user = new User();
        $user->setLogin('user.0001');
        $user->setLastname('Пользователь');
        $user->setFirstname('Обычный');
        $user->setPassword($this->passwordHasher->hashPassword($user, '1234'));
        $this->attachRole($user, 'ROLE_USER');
        $manager->persist($user);

        $manager->flush();
    }

    private function attachRole(User $user, string $roleName): void
    {
        $role = $this->roleRepository->findOneBy(['name' => $roleName]);
        if ($role) {
            $user->addRoleEntity($role);
        }
    }
}
