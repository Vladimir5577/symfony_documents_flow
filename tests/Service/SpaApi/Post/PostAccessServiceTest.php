<?php

declare(strict_types=1);

namespace App\Tests\Service\SpaApi\Post;

use App\Entity\Post\Post;
use App\Entity\User\User;
use App\Service\SpaApi\Post\PostAccessService;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Видимость публикаций.
 *
 * Правило раньше существовало только в фильтре списка, а доступ по
 * идентификатору его не проверял: обычный пользователь читал чужие черновики
 * перебором id и качал их вложения. Тесты фиксируют правило целиком, включая
 * то, что удалённое не читается даже администратором.
 */
final class PostAccessServiceTest extends TestCase
{
    public function testActivePostIsVisibleToAnyUser(): void
    {
        $service = $this->serviceForRoles([]);
        $post = $this->createPost(1, $this->createUser(10), isActive: true);

        self::assertTrue($service->canView($post, $this->createUser(20)));
    }

    public function testDraftIsHiddenFromPlainUser(): void
    {
        $service = $this->serviceForRoles([]);
        $post = $this->createPost(1, $this->createUser(10), isActive: false);

        self::assertFalse($service->canView($post, $this->createUser(20)));
    }

    public function testDraftIsHiddenFromManagerWhoIsNotTheAuthor(): void
    {
        $service = $this->serviceForRoles(['ROLE_MANAGER']);
        $post = $this->createPost(1, $this->createUser(10), isActive: false);

        self::assertFalse($service->canView($post, $this->createUser(20)));
    }

    public function testManagerSeesOwnDraft(): void
    {
        $service = $this->serviceForRoles(['ROLE_MANAGER']);
        $author = $this->createUser(10);
        $post = $this->createPost(1, $author, isActive: false);

        self::assertTrue($service->canView($post, $author));
    }

    public function testAdminSeesAnyDraft(): void
    {
        $service = $this->serviceForRoles(['ROLE_ADMIN']);
        $post = $this->createPost(1, $this->createUser(10), isActive: false);

        self::assertTrue($service->canView($post, $this->createUser(20)));
    }

    public function testDeletedPostIsNotReadableEvenForAdmin(): void
    {
        $service = $this->serviceForRoles(['ROLE_ADMIN']);
        $post = $this->createPost(1, $this->createUser(10), isActive: true);
        $post->setDeletedAt(new \DateTimeImmutable());

        self::assertFalse($service->isReadable($post, $this->createUser(20)));
    }

    public function testMissingPostIsNotReadable(): void
    {
        $service = $this->serviceForRoles(['ROLE_ADMIN']);

        self::assertFalse($service->isReadable(null, $this->createUser(20)));
    }

    public function testLivePublishedPostIsReadable(): void
    {
        $service = $this->serviceForRoles([]);
        $post = $this->createPost(1, $this->createUser(10), isActive: true);

        self::assertTrue($service->isReadable($post, $this->createUser(20)));
    }

    /**
     * @param list<string> $roles
     */
    private function serviceForRoles(array $roles): PostAccessService
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => \in_array($attribute, $roles, true),
        );

        return new PostAccessService($security);
    }

    private function createUser(int $id): User
    {
        $user = new User();
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }

    private function createPost(int $id, User $author, bool $isActive): Post
    {
        $post = new Post();
        $post->setAuthor($author);
        $post->setIsActive($isActive);

        $reflection = new \ReflectionProperty(Post::class, 'id');
        $reflection->setValue($post, $id);

        return $post;
    }
}
