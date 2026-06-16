<?php

declare(strict_types=1);

namespace App\Tests\Service\SpaApi\Documents;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentHistory;
use App\Entity\Document\DocumentUserRecipient;
use App\Entity\User\User;
use App\Enum\Document\DocumentRecipientRole;
use App\Enum\Document\DocumentStatus;
use App\Repository\Document\DocumentHistoryRepository;
use App\Repository\User\UserRepository;
use App\Service\SpaApi\Documents\DocumentAccessService;
use App\Service\SpaApi\Documents\DocumentApiPresenter;
use App\Service\SpaApi\Documents\DocumentHistoryService;
use App\Service\SpaApi\Documents\DocumentRecipientViewService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class DocumentHistoryServiceTest extends TestCase
{
    private DocumentHistoryRepository&MockObject $historyRepository;
    private UserRepository&MockObject $userRepository;
    private DocumentAccessService $accessService;
    private DocumentApiPresenter $presenter;
    private DocumentHistoryService $service;

    protected function setUp(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(false);

        $this->historyRepository = $this->createMock(DocumentHistoryRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->accessService = new DocumentAccessService($security);
        $this->presenter = new DocumentApiPresenter();
        $this->service = new DocumentHistoryService(
            $this->historyRepository,
            $this->userRepository,
            $this->accessService,
            $this->presenter,
        );
    }

    public function testGetIncomingRecipientHistoryReturnsItemsOrderedByRepository(): void
    {
        $viewer = $this->createUser(10, 'Viewer');
        $historyUser = $this->createUser(20, 'History');
        $document = $this->createDocument(85, 'docTest 44', $viewer, [$historyUser]);

        $historyItem = new DocumentHistory();
        $historyItem->setDocument($document);
        $historyItem->setUser($historyUser);
        $historyItem->setAction('Пользователь просмотрел документ');
        $historyItem->setOldStatus(DocumentStatus::NEW);
        $historyItem->setNewStatus(DocumentStatus::VIEWED);
        $historyItem->setCreatedAt(new \DateTimeImmutable('2026-06-15 13:05:00'));

        $this->userRepository
            ->expects(self::once())
            ->method('find')
            ->with(20)
            ->willReturn($historyUser);

        $this->historyRepository
            ->expects(self::once())
            ->method('findByDocumentAndUserOrderByCreatedAtDesc')
            ->with(85, 20)
            ->willReturn([$historyItem]);

        $payload = $this->service->getIncomingRecipientHistory($document, $viewer, 20);

        self::assertSame(85, $payload['document']['id']);
        self::assertSame('docTest 44', $payload['document']['name']);
        self::assertSame(20, $payload['historyUser']['id']);
        self::assertCount(1, $payload['items']);
        self::assertSame('Пользователь просмотрел документ', $payload['items'][0]['action']);
        self::assertSame('NEW', $payload['items'][0]['oldStatus']);
        self::assertSame('VIEWED', $payload['items'][0]['newStatus']);
    }

    public function testIncomingHistoryForbiddenForStranger(): void
    {
        $creator = $this->createUser(1, 'Creator');
        $stranger = $this->createUser(99, 'Stranger');
        $document = $this->createDocument(85, 'docTest 44', $creator, []);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage(SpaApiError::ACCESS_DENIED);

        $this->service->getIncomingRecipientHistory($document, $stranger, 1);
    }

    public function testOutgoingHistoryForbiddenForRecipient(): void
    {
        $creator = $this->createUser(1, 'Creator');
        $recipient = $this->createUser(2, 'Recipient');
        $document = $this->createDocument(85, 'docTest 44', $creator, [$recipient]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage(SpaApiError::ACCESS_DENIED);

        $this->service->getOutgoingRecipientHistory($document, $recipient, 2);
    }

    private function createUser(int $id, string $login): User
    {
        $user = new User();
        $user->setLogin($login);
        $user->setEmail($login . '@example.com');
        $user->setFirstname('First');
        $user->setLastname('Last');

        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }

    /**
     * @param list<User> $participantUsers
     */
    private function createDocument(int $id, string $name, User $creator, array $participantUsers): Document
    {
        $document = new Document();
        $document->setName($name);
        $document->setCreatedBy($creator);
        $document->setStatus(DocumentStatus::NEW);
        $document->setIsPublished(true);

        $reflection = new \ReflectionProperty(Document::class, 'id');
        $reflection->setValue($document, $id);

        foreach ($participantUsers as $participant) {
            $recipient = new DocumentUserRecipient();
            $recipient->setDocument($document);
            $recipient->setUser($participant);
            $recipient->setRole(DocumentRecipientRole::RECIPIENT);
            $recipient->setStatus(DocumentStatus::NEW);
            $document->addUserRecipient($recipient);
        }

        return $document;
    }
}

final class DocumentRecipientViewServiceTest extends TestCase
{
    public function testMarkViewedCreatesHistoryEntryForNewRecipient(): void
    {
        $user = $this->createUser(10, 'Viewer');
        $document = $this->createDocumentWithRecipient(85, $user, DocumentStatus::NEW);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(false);
        $accessService = new DocumentAccessService($security);

        $service = new DocumentRecipientViewService($accessService, $entityManager);
        $service->markViewedIfNeeded($document, $user);

        $recipient = $accessService->findUserRecipient($document, $user);
        self::assertNotNull($recipient);
        self::assertSame(DocumentStatus::VIEWED, $recipient->getStatus());
    }

    public function testMarkViewedIsIdempotentWhenStatusIsNotNew(): void
    {
        $user = $this->createUser(10, 'Viewer');
        $document = $this->createDocumentWithRecipient(85, $user, DocumentStatus::VIEWED);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(false);
        $accessService = new DocumentAccessService($security);

        $service = new DocumentRecipientViewService($accessService, $entityManager);
        $service->markViewedIfNeeded($document, $user);
    }

    private function createUser(int $id, string $login): User
    {
        $user = new User();
        $user->setLogin($login);
        $user->setEmail($login . '@example.com');
        $user->setFirstname('First');
        $user->setLastname('Last');

        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }

    private function createDocumentWithRecipient(int $id, User $user, DocumentStatus $status): Document
    {
        $document = new Document();
        $document->setName('docTest');
        $document->setStatus(DocumentStatus::NEW);
        $document->setIsPublished(true);

        $reflection = new \ReflectionProperty(Document::class, 'id');
        $reflection->setValue($document, $id);

        $recipient = new DocumentUserRecipient();
        $recipient->setDocument($document);
        $recipient->setUser($user);
        $recipient->setRole(DocumentRecipientRole::RECIPIENT);
        $recipient->setStatus($status);
        $document->addUserRecipient($recipient);

        return $document;
    }
}
