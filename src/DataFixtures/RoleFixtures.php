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
        $repo = $manager->getRepository(Role::class);

        foreach (UserRole::cases() as $userRole) {
            if ($repo->findOneBy(['name' => $userRole])) {
                continue;
            }
            $role = new Role($userRole);
            $role->setLabel($userRole->getLabel());
            $manager->persist($role);
        }

        $manager->flush();
    }
}
