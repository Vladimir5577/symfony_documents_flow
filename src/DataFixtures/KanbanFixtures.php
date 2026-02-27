<?php

namespace App\DataFixtures;

use App\Entity\Kanban\KanbanBoard;
use App\Entity\Kanban\KanbanBoardMember;
use App\Entity\Kanban\KanbanCard;
use App\Entity\Kanban\KanbanChecklistItem;
use App\Entity\Kanban\KanbanColumn;
use App\Entity\User\User;
use App\Enum\KanbanBoardMemberRole;
use App\Enum\KanbanCardPriority;
use App\Enum\KanbanColumnColor;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

class KanbanFixtures extends Fixture implements FixtureGroupInterface
{
    public static function getGroups(): array
    {
        return ['base'];
    }

    public function load(ObjectManager $manager): void
    {
        // Берём первого пользователя (ID=1)
        $user = $manager->getRepository(User::class)->find(1);
        if (!$user) {
            echo "KanbanFixtures: пользователь с ID=1 не найден, пропуск.\n";
            return;
        }

        // --- Доска ---
        $board = new KanbanBoard();
        $board->setTitle('Донснабкомплект — Договоры');
        $board->setCreatedBy($user);
        $manager->persist($board);

        // --- Участник ---
        $member = new KanbanBoardMember();
        $member->setBoard($board);
        $member->setUser($user);
        $member->setRole(KanbanBoardMemberRole::ADMIN);
        $board->addMember($member);
        $manager->persist($member);

        // --- Колонки ---
        $colNew = new KanbanColumn();
        $colNew->setTitle('Новые');
        $colNew->setHeaderColor(KanbanColumnColor::BG_PRIMARY);
        $colNew->setPosition(1.0);
        $colNew->setBoard($board);
        $manager->persist($colNew);

        $colInProgress = new KanbanColumn();
        $colInProgress->setTitle('В работе');
        $colInProgress->setHeaderColor(KanbanColumnColor::BG_WARNING);
        $colInProgress->setPosition(2.0);
        $colInProgress->setBoard($board);
        $manager->persist($colInProgress);

        $colDone = new KanbanColumn();
        $colDone->setTitle('Готово');
        $colDone->setHeaderColor(KanbanColumnColor::BG_SUCCESS);
        $colDone->setPosition(3.0);
        $colDone->setBoard($board);
        $manager->persist($colDone);

        // --- Карточки ---
        $card1 = new KanbanCard();
        $card1->setTitle('Договор поставки №45');
        $card1->setDescription('Подготовить договор поставки строительных материалов для объекта "Жилой комплекс Рассвет".');
        $card1->setPriority(KanbanCardPriority::HIGH);
        $card1->setPosition(1.0);
        $card1->setColumn($colNew);
        $manager->persist($card1);

        $card2 = new KanbanCard();
        $card2->setTitle('Акт приёмки работ');
        $card2->setDescription('Оформить акт приёмки выполненных работ по объекту "Склад №3".');
        $card2->setPriority(KanbanCardPriority::MEDIUM);
        $card2->setPosition(2.0);
        $card2->setColumn($colNew);
        $manager->persist($card2);

        $card3 = new KanbanCard();
        $card3->setTitle('Счёт-фактура за февраль');
        $card3->setDescription('Выставить счёт-фактуру за февраль по договору обслуживания.');
        $card3->setPriority(KanbanCardPriority::LOW);
        $card3->setPosition(1.0);
        $card3->setColumn($colInProgress);
        $manager->persist($card3);

        // --- Чеклист для card1 ---
        $ci1 = new KanbanChecklistItem();
        $ci1->setTitle('Согласовать с юристом');
        $ci1->setIsCompleted(true);
        $ci1->setPosition(1.0);
        $ci1->setCard($card1);
        $manager->persist($ci1);

        $ci2 = new KanbanChecklistItem();
        $ci2->setTitle('Получить подпись директора');
        $ci2->setIsCompleted(false);
        $ci2->setPosition(2.0);
        $ci2->setCard($card1);
        $manager->persist($ci2);

        $manager->flush();

        echo "KanbanFixtures: доска, 3 колонки, 3 карточки, 2 подзадачи — готово.\n";
    }
}
