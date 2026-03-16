<?php

namespace App\Service\Chat;

use App\Entity\Chat\ChatParticipant;
use App\Entity\Chat\ChatRoom;
use App\Entity\Organization\Department;
use App\Entity\User\User;
use App\Enum\Chat\ChatRoomType;
use App\Repository\Chat\ChatParticipantRepository;
use App\Repository\Chat\ChatRoomRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class ChatRoomService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ChatRoomRepository $roomRepository,
        private ChatParticipantRepository $participantRepository,
        private UserRepository $userRepository,
    ) {
    }

    public function createPrivateRoom(User $user1, User $user2): ChatRoom
    {
        $existing = $this->roomRepository->findPrivateRoomBetween($user1, $user2);
        if ($existing) {
            return $existing;
        }

        $room = new ChatRoom();
        $room->setType(ChatRoomType::PRIVATE);
        $this->em->persist($room);

        $this->addParticipantEntity($room, $user1);
        $this->addParticipantEntity($room, $user2);

        $this->em->flush();

        return $room;
    }

    public function createGroupRoom(User $creator, string $name, array $participantUsers): ChatRoom
    {
        $room = new ChatRoom();
        $room->setType(ChatRoomType::GROUP);
        $room->setName($name);
        $room->setCreatedBy($creator);
        $this->em->persist($room);

        $this->addParticipantEntity($room, $creator);
        foreach ($participantUsers as $user) {
            if ($user->getId() !== $creator->getId()) {
                $this->addParticipantEntity($room, $user);
            }
        }

        $this->em->flush();

        return $room;
    }

    public function createDepartmentRoom(User $creator, Department $dept): ChatRoom
    {
        $room = new ChatRoom();
        $room->setType(ChatRoomType::DEPARTMENT);
        $room->setName($dept->getName());
        $room->setOrganization($dept);
        $room->setCreatedBy($creator);
        $this->em->persist($room);

        $users = $this->userRepository->findByOrganization($dept);
        foreach ($users as $user) {
            $this->addParticipantEntity($room, $user);
        }

        if (!$this->participantRepository->isParticipant($room, $creator)) {
            $this->addParticipantEntity($room, $creator);
        }

        $this->em->flush();

        return $room;
    }

    public function addParticipant(ChatRoom $room, User $user): void
    {
        if ($this->participantRepository->isParticipant($room, $user)) {
            return;
        }

        $this->addParticipantEntity($room, $user);
        $this->em->flush();
    }

    public function removeParticipant(ChatRoom $room, User $user): void
    {
        $participant = $this->participantRepository->findOneBy(['room' => $room, 'user' => $user]);
        if ($participant) {
            $this->em->remove($participant);
            $this->em->flush();
        }
    }

    public function getUserRooms(User $user): array
    {
        return $this->roomRepository->findUserRooms($user);
    }

    public function findPrivateRoom(User $user1, User $user2): ?ChatRoom
    {
        return $this->roomRepository->findPrivateRoomBetween($user1, $user2);
    }

    private function addParticipantEntity(ChatRoom $room, User $user): void
    {
        $participant = new ChatParticipant();
        $participant->setRoom($room);
        $participant->setUser($user);
        $this->em->persist($participant);
    }
}
