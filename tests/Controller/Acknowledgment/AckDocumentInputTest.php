<?php

declare(strict_types=1);

namespace App\Tests\Controller\Acknowledgment;

use App\Controller\SpaApi\Acknowledgment\AckAdminDocumentController;
use App\Controller\SpaApi\SpaApiError;
use App\Entity\Acknowledgment\AckCategory;
use App\Entity\Acknowledgment\AckDocument;
use App\Entity\Acknowledgment\AckDocumentUser;
use App\Enum\Acknowledgment\AckAudience;
use App\Repository\Acknowledgment\AckDocumentRepository;
use App\Repository\Acknowledgment\AckDocumentUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AckDocumentInputTest extends KernelTestCase
{
    private AckAdminDocumentController $controller;
    private AckDocument $document;
    private AckDocumentUserRepository&MockObject $ackRepo;
    private EntityManagerInterface&MockObject $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->document = (new AckDocument())
            ->setTitle('Original title')
            ->setCategory(new AckCategory())
            ->setAudience(AckAudience::ALL)
            ->setPublishedAt(new \DateTimeImmutable());

        $documentRepo = $this->createMock(AckDocumentRepository::class);
        $documentRepo->method('find')->with(1)->willReturn($this->document);
        $this->ackRepo = $this->createMock(AckDocumentUserRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        // Проверяем настоящий контроллер и контейнер, не подключаясь к базе.
        $container->set(AckDocumentRepository::class, $documentRepo);
        $container->set(AckDocumentUserRepository::class, $this->ackRepo);
        $container->set('doctrine.orm.entity_manager', $this->em);
        $this->controller = $container->get(AckAdminDocumentController::class);
    }

    public static function invalidRecipientsProvider(): iterable
    {
        foreach ([null, false, 1, '1', new \stdClass(), (object) ['id' => 1]] as $value) {
            yield 'published ' . var_export($value, true) => [$value, true];
            yield 'draft ' . var_export($value, true) => [$value, false];
        }
    }

    #[DataProvider('invalidRecipientsProvider')]
    public function testInvalidRecipientsAreRejectedBeforeChanges(mixed $userIds, bool $published): void
    {
        if (!$published) {
            $this->document->setPublishedAt(null);
        }

        $this->ackRepo->expects(self::never())->method('findBy');
        $this->em->expects(self::never())->method('remove');
        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');

        $response = $this->controller->update(1, $this->request([
            'title' => 'Changed title',
            'userIds' => $userIds,
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            ['error' => SpaApiError::ACK_DOCUMENT_NO_RECIPIENTS],
            json_decode((string) $response->getContent(), true),
        );
        self::assertSame('Original title', $this->document->getTitle());
    }

    public function testPublishedRecipientsCannotBeChangedWithValidArray(): void
    {
        $this->ackRepo->expects(self::never())->method('findBy');
        $this->em->expects(self::never())->method('remove');
        $this->em->expects(self::never())->method('flush');

        $response = $this->controller->update(1, $this->request(['userIds' => []]));

        self::assertSame(409, $response->getStatusCode());
    }

    public function testPublishedTextCanBeChangedWithoutRemovingAcknowledgments(): void
    {
        $this->ackRepo->expects(self::never())->method('findBy');
        $this->em->expects(self::never())->method('remove');
        $this->em->expects(self::once())->method('flush');

        $response = $this->controller->update(1, $this->request(['title' => 'Changed title']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Changed title', $this->document->getTitle());
    }

    public function testValidDraftAudienceUpdateStillRemovesAssignments(): void
    {
        $this->document->setPublishedAt(null);
        $assignment = (new AckDocumentUser())->setDocument($this->document);
        $this->ackRepo->expects(self::once())->method('findBy')
            ->with(['document' => $this->document])->willReturn([$assignment]);
        $this->em->expects(self::once())->method('remove')->with($assignment);
        $this->em->expects(self::once())->method('flush');

        $response = $this->controller->update(1, $this->request(['userIds' => []]));

        self::assertSame(200, $response->getStatusCode());
    }

    private function request(array $payload): Request
    {
        return Request::create(
            '/spa/api/acknowledgment/admin/documents/1',
            'PATCH',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
