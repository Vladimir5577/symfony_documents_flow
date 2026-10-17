<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    public static function getGroups(): array
    {
        return ['base'];
    }

    public function load(ObjectManager $manager): void
    {

    }

    public function getDependencies(): array
    {
        return [
            AdminStartFixtures::class,
            DocumentTypeFixtures::class,
            OrganizationFixtures::class,
            UserFixtures::class,
        ];
    }
}
