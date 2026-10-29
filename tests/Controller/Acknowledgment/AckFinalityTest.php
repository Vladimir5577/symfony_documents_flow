<?php

declare(strict_types=1);

namespace App\Tests\Controller\Acknowledgment;

use App\Controller\SpaApi\Acknowledgment\AckDocumentController;
use App\Controller\SpaApi\SpaApiError;
use App\Entity\Acknowledgment\AckDocument;
use App\Entity\Acknowledgment\AckDocumentUser;
use App\Entity\User\User;
use App\Enum\Acknowledgment\AckStatus;
use App\Repository\Acknowledgment\AckDocumentRepository;
use App\Repository\Acknowledgment\AckDocumentUserRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AckFinalityTest extends KernelTestCase
{
    public static function finalStatuses(): iterable
    {
        foreach ([AckStatus::ACKNOWLEDGED, AckStatus::DISAGREED] as $final) {
            foreach ([AckStatus::LATER, AckStatus::ACKNOWLEDGED, AckStatus::DISAGREED] as $next) {
                yield $final->value . ' to ' . $next->value => [$final, $next];
            }
        }
    }

    #[DataProvider('finalStatuses')]
    public function testConcurrentFinalDecisionIsRereadAndCannotBeOverwritten(AckStatus $final, AckStatus $next): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $document = (new AckDocument())->setPublishedAt(new \DateTimeImmutable());
        $user = new User();
        $ack = (new AckDocumentUser())->setDocument($document)->setUser($user)->setStatus(AckStatus::LATER);
        $documents = $this->createMock(AckDocumentRepository::class);
        $documents->method('find')->willReturn($document);
        $documents->method('isAddressedTo')->willReturn(true);
        $acks = $this->createMock(AckDocumentUserRepository::class);
        $acks->method('findOneFor')->willReturn($ack);
        $em = $this->createMock(EntityManagerInterface::class);
        $inTransaction = false;
        $em->expects(self::once())->method('wrapInTransaction')->willReturnCallback(
            static function (callable $callback) use (&$inTransaction) {
                $inTransaction = true;
                try {
                    return $callback();
                } finally {
                    $inTransaction = false;
                }
            },
        );
        $em->expects(self::exactly(2))->method('refresh')->willReturnCallback(
            static function (object $entity, mixed $mode) use (&$inTransaction, $ack, $final): void {
                self::assertTrue($inTransaction);
                self::assertSame(LockMode::PESSIMISTIC_WRITE, $mode);
                if ($entity === $ack) {
                    // Второй запрос уже загрузил LATER, первый успел сохранить final.
                    $ack->setStatus($final)->setComment('First decision');
                }
            },
        );
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $container->set(AckDocumentRepository::class, $documents);
        $container->set(AckDocumentUserRepository::class, $acks);
        $container->set('doctrine.orm.entity_manager', $em);

        $response = $container->get(AckDocumentController::class)->setStatus(1, Request::create(
            '/', 'POST', content: json_encode(['status' => $next->value, 'comment' => 'Second decision'], JSON_THROW_ON_ERROR),
        ), $user);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['error' => SpaApiError::ACK_STATUS_ALREADY_FINAL], json_decode($response->getContent(), true));
        self::assertSame($final, $ack->getStatus());
        self::assertSame('First decision', $ack->getComment());
    }

    public function testNonfinalDecisionCanStillBecomeFinalInsideTransaction(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $document = (new AckDocument())->setPublishedAt(new \DateTimeImmutable());
        $user = new User();
        $ack = (new AckDocumentUser())->setDocument($document)->setUser($user)->setStatus(AckStatus::LATER);
        $documents = $this->createMock(AckDocumentRepository::class);
        $documents->method('find')->willReturn($document);
        $documents->method('isAddressedTo')->willReturn(true);
        $acks = $this->createMock(AckDocumentUserRepository::class);
        $acks->method('findOneFor')->willReturn($ack);
        $em = $this->createMock(EntityManagerInterface::class);
        $inTransaction = false;
        $em->expects(self::once())->method('wrapInTransaction')->willReturnCallback(
            static function (callable $callback) use (&$inTransaction) {
                $inTransaction = true;
                try {
                    return $callback();
                } finally {
                    $inTransaction = false;
                }
            },
        );
        $em->expects(self::exactly(2))->method('refresh')->willReturnCallback(
            static function (object $entity, mixed $mode) use (&$inTransaction): void {
                self::assertTrue($inTransaction);
                self::assertSame(LockMode::PESSIMISTIC_WRITE, $mode);
            },
        );
        $em->expects(self::once())->method('flush')->willReturnCallback(static function () use (&$inTransaction, $ack): void {
            self::assertTrue($inTransaction);
            self::assertSame(AckStatus::ACKNOWLEDGED, $ack->getStatus());
        });
        $container->set(AckDocumentRepository::class, $documents);
        $container->set(AckDocumentUserRepository::class, $acks);
        $container->set('doctrine.orm.entity_manager', $em);

        $response = $container->get(AckDocumentController::class)->setStatus(1,
            Request::create('/', 'POST', content: '{"status":"ACKNOWLEDGED"}'), $user);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(AckStatus::ACKNOWLEDGED, $ack->getStatus());
    }
}
