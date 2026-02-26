<?php

namespace App\DataFixtures;

use App\Entity\User\Worker;
use App\Repository\User\UserRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class WorkerFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private readonly UserRepository $userRepository
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Получаем всех пользователей
        $users = $this->userRepository->findAll();

        if (empty($users)) {
            return; // Если нет пользователей, не создаем работников
        }

        // Профессии для работников
        $professions = [
            'Инженер-строитель',
            'Прораб',
            'Мастер',
            'Сварщик',
            'Плотник',
            'Каменщик',
            'Крановщик',
            'Электрик',
            'Сантехник',
            'Штукатур',
            'Маляр',
            'Архитектор',
            'Проектировщик',
            'Геодезист',
            'Бухгалтер',
            'Экономист',
            'Менеджер проекта',
            'Сметчик',
            'Охрана',
            'Водитель',
        ];

        $descriptions = [
            'Опытный специалист с большим стажем работы',
            'Молодой специалист, быстро обучается',
            'Высококвалифицированный работник',
            'Специалист широкого профиля',
            'Эксперт в своей области',
            null, // Некоторые без описания
            null,
        ];

        // Создаем работников для части пользователей (примерно 60%)
        $workersCount = (int) ceil(count($users) * 0.6);
        $selectedUsers = array_slice($users, 0, $workersCount);

        foreach ($selectedUsers as $index => $user) {
            $worker = new Worker();
            $worker->setUserId($user->getId());
            $worker->setProfession($professions[array_rand($professions)]);

            // Случайное описание (70% имеют описание)
            if (rand(0, 100) < 70) {
                $description = $descriptions[array_rand($descriptions)];
                if ($description !== null) {
                    $worker->setDescription($description);
                }
            }

            $manager->persist($worker);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }
}
