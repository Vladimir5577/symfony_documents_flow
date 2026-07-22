<?php

declare(strict_types=1);

namespace App\Tests\Repository\Post;

use App\Entity\Post\Post;
use App\Entity\User\User;
use App\Enum\Post\PostType;
use App\Enum\Post\PostUserStatusType;
use App\Repository\Post\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Проверяет построение DQL для SPA-ленты публикаций без обращения к БД:
 * репозиторий работает поверх «безсоединительного» EntityManager, а мы
 * инспектируем итоговый QueryBuilder.
 */
final class PostRepositoryTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private PostRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->entityManager->method('getClassMetadata')->willReturn(new ClassMetadata(Post::class));
        $this->entityManager->method('getExpressionBuilder')->willReturn(new Expr());
        $this->entityManager->method('createQueryBuilder')
            ->willReturnCallback(fn (): QueryBuilder => new QueryBuilder($this->entityManager));

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);

        $this->repository = new PostRepository($registry);
    }

    public function testAllPostsQueryFiltersByVisibilityOnly(): void
    {
        $qb = $this->buildQuery(null, false);
        $dql = $qb->getDQL();

        self::assertStringContainsString('p.isActive = true', $dql);
        self::assertStringContainsString('p.deletedAt IS NULL', $dql);
        self::assertStringNotContainsString('p.type = :type', $dql);
        self::assertStringNotContainsString('LEFT JOIN', $dql);
        self::assertStringNotContainsString('p.isRequiredAcknowledgment', $dql);
    }

    public function testTypeFilterIsApplied(): void
    {
        $qb = $this->buildQuery(PostType::NEWS, false);

        self::assertStringContainsString('p.type = :type', $qb->getDQL());
        self::assertSame(PostType::NEWS, $this->parameterValue($qb, 'type'));
    }

    public function testUnacknowledgedOnlyJoinsUserStatusAndExcludesAcknowledged(): void
    {
        $qb = $this->buildQuery(null, true);
        $dql = $qb->getDQL();

        self::assertStringContainsString('LEFT JOIN App\Entity\Post\PostUserStatus us', $dql);
        self::assertStringContainsString('us.post = p AND us.user = :user', $dql);
        self::assertStringContainsString('p.isRequiredAcknowledgment = true', $dql);
        self::assertStringContainsString('us.id IS NULL OR us.status <> :acknowledged', $dql);

        self::assertSame(PostUserStatusType::ACKNOWLEDGED, $this->parameterValue($qb, 'acknowledged'));
    }

    public function testUnacknowledgedOnlyIgnoresTypeButKeepsVisibilityFilters(): void
    {
        $qb = $this->buildQuery(PostType::ORDER, true);
        $dql = $qb->getDQL();

        self::assertStringContainsString('p.isActive = true', $dql);
        self::assertStringContainsString('p.deletedAt IS NULL', $dql);
        self::assertStringContainsString('p.type = :type', $dql);
        self::assertStringContainsString('p.isRequiredAcknowledgment = true', $dql);
    }

    private function buildQuery(?PostType $type, bool $unacknowledgedOnly): QueryBuilder
    {
        $method = new \ReflectionMethod(PostRepository::class, 'createActiveForSpaQueryBuilder');

        return $method->invoke($this->repository, $type, new User(), $unacknowledgedOnly);
    }

    private function parameterValue(QueryBuilder $qb, string $name): mixed
    {
        $parameter = $qb->getParameter($name);
        self::assertInstanceOf(Parameter::class, $parameter);

        return $parameter->getValue();
    }
}
