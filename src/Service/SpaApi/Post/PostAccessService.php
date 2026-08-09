<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Post;

use App\Entity\Post\Post;
use App\Entity\User\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Видимость публикаций.
 *
 * Раньше правило жило только внутри списка (PostController::list): фильтр
 * is_active=0 закрывался ролью и авторством. Доступ по идентификатору —
 * просмотр, ознакомление, комментарии, скачивание вложения — правила не знал
 * и отдавал что угодно любому ROLE_USER, если тот подобрал числовой id.
 *
 * Один класс на всю политику, по образцу DocumentAccessService: чтобы правило
 * нельзя было забыть применить в новом методе контроллера, и чтобы его можно
 * было проверить тестом без поднятия ядра.
 */
final class PostAccessService
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    /**
     * Опубликованное видно всем; неопубликованное — администратору целиком,
     * менеджеру только своё.
     */
    public function canView(Post $post, User $user): bool
    {
        if ($post->isActive()) {
            return true;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return $this->security->isGranted('ROLE_MANAGER')
            && $post->getAuthor()?->getId() === $user->getId();
    }

    /**
     * Удалённая публикация не видна никому, включая администратора: доступ к
     * ней — отдельная задача восстановления, а не обычное чтение.
     */
    public function isReadable(?Post $post, User $user): bool
    {
        if ($post === null || $post->getDeletedAt() !== null) {
            return false;
        }

        return $this->canView($post, $user);
    }
}
