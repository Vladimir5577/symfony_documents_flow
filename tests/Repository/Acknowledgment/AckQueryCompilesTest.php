<?php

declare(strict_types=1);

namespace App\Tests\Repository\Acknowledgment;

use App\Entity\Acknowledgment\AckDocument;
use App\Entity\Acknowledgment\AckDocumentUser;
use App\Entity\User\User;
use App\Enum\Acknowledgment\AckAudience;
use App\Repository\Acknowledgment\AckDocumentUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Запросы модуля компилируются в SQL.
 *
 * Живой базы тест не трогает: getSQL() только собирает запрос. Смысл в том, что
 * весь модуль держится на нескольких DQL — переименуют поле или сломают join,
 * и молча отвалится либо счётчик, либо отчёт. Здесь это падает сразу.
 *
 * Строители запросов приватные, поэтому берём их рефлексией: проверять хочется
 * именно тот код, который работает в проде, а не его копию в тесте.
 */
final class AckQueryCompilesTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
    }

    /**
     * @return iterable<string, array{class-string, string, list<mixed>}>
     */
    public static function queryProvider(): iterable
    {
        $user = new User();

        yield 'назначенные адресно' => [AckDocument::class, 'pendingSelectedQb', [$user]];
        yield 'для всех, без моей отметки' => [AckDocument::class, 'pendingAllQb', [$user]];
        yield 'реестр делопроизводства' => [AckDocument::class, 'registryQb', [null, false]];
        yield 'мои закрытые' => [AckDocumentUser::class, 'historyQb', [$user]];
        yield 'счётчики по документам' => [AckDocumentUser::class, 'statusCountsQb', [[1, 2]]];
        yield 'отчёт, аудитория «все»' => [
            AckDocumentUser::class,
            'reportQb',
            [(new AckDocument())->setAudience(AckAudience::ALL), AckDocumentUserRepository::FILTER_PENDING],
        ];
        yield 'отчёт, адресный документ' => [
            AckDocumentUser::class,
            'reportQb',
            [(new AckDocument())->setAudience(AckAudience::SELECTED), 'ACKNOWLEDGED'],
        ];
    }

    /**
     * @param class-string $entityClass
     * @param list<mixed>  $args
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('queryProvider')]
    public function testQueryCompilesToSql(string $entityClass, string $method, array $args): void
    {
        $repository = $this->em->getRepository($entityClass);

        $builder = new \ReflectionMethod($repository, $method);
        /** @var QueryBuilder $qb */
        $qb = $builder->invokeArgs($repository, $args);

        // Отчёту список полей задаёт вызывающий — без него запрос неполон.
        if ($method === 'reportQb') {
            $qb->select('u.id', 'adu.status');
        }

        self::assertNotSame('', $qb->getQuery()->getSQL());
    }

    /**
     * Условие «моей отметки нет» обязано жить в ON, а не в WHERE: в WHERE
     * left join схлопывается в inner, и документы «для всех» пропадут у тех,
     * кто их ещё не открывал, — то есть ровно у тех, кому они нужны.
     */
    public function testPendingForAllKeepsJoinConditionInOn(): void
    {
        $repository = $this->em->getRepository(AckDocument::class);
        $builder = new \ReflectionMethod($repository, 'pendingAllQb');

        /** @var QueryBuilder $qb */
        $qb = $builder->invokeArgs($repository, [new User()]);
        $sql = $qb->getQuery()->getSQL();

        self::assertMatchesRegularExpression(
            '/LEFT JOIN ack_document_user \w+ ON \([^)]*user_id = \?\)/',
            $sql,
        );
    }

    /**
     * Пустой статус — такой же «не ознакомлен», как и отсутствие строки.
     *
     * Строка со статусом NULL остаётся, если черновик заводили адресным, а
     * опубликовали на всех. Без этой ветки документ не показывался бы ровно
     * тем, кого когда-то выбрали поимённо, и молча: ошибки нет, просто пусто.
     */
    public function testPendingForAllTreatsEmptyStatusAsPending(): void
    {
        $repository = $this->em->getRepository(AckDocument::class);
        $builder = new \ReflectionMethod($repository, 'pendingAllQb');

        /** @var QueryBuilder $qb */
        $qb = $builder->invokeArgs($repository, [new User()]);

        self::assertMatchesRegularExpression(
            '/\w+\.id IS NULL OR \w+\.status IS NULL OR \w+\.status = \?/',
            $qb->getQuery()->getSQL(),
        );
    }
}
