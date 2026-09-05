<?php

namespace App\EntityListener;

use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;

/**
 * Doctrine Entity Listener на User — при смене пароля удаляет все его refresh-токены.
 *
 * Без этого сброс пароля не выкидывает того, кто уже вошёл: access-токен живёт час,
 * но старый refresh (ttl gesdinet по умолчанию — 30 дней) молча выдаёт новые. Админ
 * сбросил пароль скомпрометированному аккаунту, а доступ остался.
 *
 * Уже выданный access-токен сознательно не отзывается: его проверяют пять Go-сервисов
 * по публичному ключу, без похода в БД, так что отзыв на стороне PHP их всё равно не
 * касается. Окно до часа принято как есть.
 *
 * Листенер на сущности, а не вызов в контроллерах: точек смены пароля три
 * (UserController — админом, MeController — самим собой, UserRepository::upgradePassword),
 * и появятся ещё.
 */
#[AsEntityListener(event: 'preUpdate', entity: User::class)]
final class RevokeRefreshTokensEntityListener
{
    public function preUpdate(User $user, PreUpdateEventArgs $event): void
    {
        if (!$event->hasChangedField('password')) {
            return;
        }

        // В refresh_tokens нет связи с User — только строка логина. Логин мог смениться
        // тем же запросом (UserController правит оба поля разом), тогда токены лежат
        // под старым значением.
        $login = $event->hasChangedField('login')
            ? $event->getOldValue('login')
            : $user->getLogin();

        // DQL DELETE, а не findBy + remove: внутри preUpdate нельзя трогать UnitOfWork,
        // а запрос уходит в БД напрямую и в той же транзакции, что и сам flush.
        $event->getObjectManager()
            ->createQuery('DELETE FROM App\Entity\User\RefreshToken t WHERE t.username = :login')
            ->setParameter('login', $login)
            ->execute();
    }
}
