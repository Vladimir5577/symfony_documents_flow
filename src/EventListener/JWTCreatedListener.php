<?php

namespace App\EventListener;

use App\Entity\User\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Добавляет claim `id` (числовой User.id) в JWT payload.
 *
 * По умолчанию Lexik кладёт только `username` (= login) и `roles`.
 * Go-микросервис канбана использует id (int) во всех 8 точках связи
 * с пользователем — без этого claim'а ему пришлось бы резолвить
 * login → id через реплику на каждую запись.
 *
 * Изменение обратно совместимо: старые claim'ы (username, roles) на месте,
 * добавлен только новый.
 */
#[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_created')]
final class JWTCreatedListener
{
    public function __invoke(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        $payload = $event->getData();
        $payload['id'] = $user->getId();
        $event->setData($payload);
    }
}
