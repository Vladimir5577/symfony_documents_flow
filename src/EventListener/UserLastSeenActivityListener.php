<?php

namespace App\EventListener;

use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

final class UserLastSeenActivityListener
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager
    ) {}

    #[AsEventListener(event: 'kernel.request')]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return;
        }

        $lastSeen = $user->getLastSeenAt();

        if ($lastSeen && $lastSeen > new \DateTimeImmutable('-5 minutes')) {
            return;
        }

        $user->setLastSeenAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }
}
