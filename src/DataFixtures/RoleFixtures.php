<?php

namespace App\DataFixtures;

use App\Entity\Role;
use App\Enum\UserRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

class RoleFixtures extends Fixture implements FixtureGroupInterface
{
    public static function getGroups(): array
    {
        return ['roles'];
    }

    public function load(ObjectManager $manager): void
    {
        // Создаем все роли из enum
        foreach (UserRole::cases() as $userRole) {
            $role = new Role($userRole->value);
            $role->setLabel($userRole->getLabel());
            $manager->persist($role);
        }

        $manager->flush();
    }
}
