<?php

namespace App\DataFixtures;

use App\Entity\Role;
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
        $roles = [
            ['name' => 'ROLE_ADMIN', 'label' => 'Администратор'],
            ['name' => 'ROLE_HR', 'label' => 'Отдел кадров'],
            ['name' => 'ROLE_USER', 'label' => 'Пользователь'],
            ['name' => 'ROLE_EDITOR', 'label' => 'Редактор'],
        ];

        foreach ($roles as $item) {
            $role = new Role($item['name']);
            $role->setLabel($item['label']);
            $manager->persist($role);
        }

        $manager->flush();
    }
}
