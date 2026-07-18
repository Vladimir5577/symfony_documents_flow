<?php

declare(strict_types=1);

namespace App\Tests\Service\SpaApi\Documents;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentType;
use App\Entity\Document\DocumentUserRecipient;
use App\Entity\Organization\Organization;
use App\Entity\User\User;
use App\Enum\Document\DocumentRecipientRole;
use App\Enum\Document\DocumentStatus;
use App\Enum\Document\SignatureLevel;
use App\Repository\Document\DocumentTypeRepository;
use App\Repository\Document\FileRepository;
use App\Repository\Organization\OrganizationRepository;
use App\Repository\User\UserRepository;
use App\Service\Notification\NotificationService;
use App\Service\SpaApi\Documents\DocumentAccessService;
use App\Service\SpaApi\Documents\DocumentApiPresenter;
use App\Service\SpaApi\Documents\DocumentAttachmentService;
use App\Service\SpaApi\Documents\DocumentCreateService;
use App\Service\SpaApi\Documents\DocumentRecipientsService;
use App\Service\SpaApi\Documents\DocumentUpdateService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class DocumentSignersTest extends TestCase
{
    private Organization $organization;
    private User $author;
    /** @var array<int, User> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->organization = new Organization();
        $reflection = new \ReflectionProperty(\App\Entity\Organization\AbstractOrganization::class, 'id');
        $reflection->setValue($this->organization, 7);

        $this->author = $this->createUser(1, 'author');
        $this->author->setOrganization($this->organization);

        $this->users = [];
        foreach ([10, 20, 30] as $id) {
            $this->users[$id] = $this->createUser($id, 'user' . $id);
        }
    }

    // --- create ---

    public function testCreateWithParallelSigners(): void
    {
        $document = $this->makeCreateService()->create($this->createPayload([
            'signatureLevel' => 'simple',
            'signers' => [
                ['userId' => 10, 'order' => 1],
                ['userId' => 20, 'order' => 1],
            ],
        ]), $this->author);

        self::assertSame(SignatureLevel::SIMPLE, $document->getSignatureLevel());
        self::assertSame([10 => 1, 20 => 1], $this->signersByUserId($document));
    }

    public function testCreateWithSequentialSigners(): void
    {
        $document = $this->makeCreateService()->create($this->createPayload([
            'signatureLevel' => 'enhanced',
            'signers' => [
                ['userId' => 10, 'order' => 1],
                ['userId' => 20, 'order' => 2],
                ['userId' => 30, 'order' => 3],
            ],
        ]), $this->author);

        self::assertSame(SignatureLevel::ENHANCED, $document->getSignatureLevel());
        self::assertSame([10 => 1, 20 => 2, 30 => 3], $this->signersByUserId($document));
    }

    public function testCreateSignersWithoutLevelFails(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_SIGNATURE_LEVEL_REQUIRED);

        $this->makeCreateService()->create($this->createPayload([
            'signers' => [['userId' => 10, 'order' => 1]],
        ]), $this->author);
    }

    public function testCreateLevelWithoutSignersFails(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_SIGNERS_REQUIRED);

        $this->makeCreateService()->create($this->createPayload([
            'signatureLevel' => 'simple',
        ]), $this->author);
    }

    public function testCreateWithInvalidSignerOrderFails(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_INVALID_SIGNERS);

        $this->makeCreateService()->create($this->createPayload([
            'signatureLevel' => 'simple',
            'signers' => [['userId' => 10, 'order' => 0]],
        ]), $this->author);
    }

    public function testCreateWithInvalidSignatureLevelFails(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_INVALID_SIGNATURE_LEVEL);

        $this->makeCreateService()->create($this->createPayload([
            'signatureLevel' => 'qualified',
            'signers' => [['userId' => 10, 'order' => 1]],
        ]), $this->author);
    }

    public function testCreateWithUnknownSignerFails(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_SIGNER_NOT_FOUND);

        $this->makeCreateService()->create($this->createPayload([
            'signatureLevel' => 'simple',
            'signers' => [
                ['userId' => 10, 'order' => 1],
                ['userId' => 999, 'order' => 2],
            ],
        ]), $this->author);
    }

    public function testUpdateWithUnknownSignerFailsAndKeepsCurrentSigners(): void
    {
        $document = $this->createSignableDocument(DocumentStatus::NEW);

        try {
            $this->makeUpdateService()->update($document, [
                'signers' => [['userId' => 999, 'order' => 1]],
            ], $this->author);
            self::fail('Expected BadRequestHttpException');
        } catch (BadRequestHttpException $e) {
            self::assertSame(SpaApiError::DOCUMENT_SIGNER_NOT_FOUND, $e->getMessage());
        }

        // существующие подписанты не должны быть удалены до валидации новых
        self::assertSame([10 => 1], $this->signersByUserId($document));
    }

    public function testCreateWithoutSignatureFieldsKeepsLegacyFlow(): void
    {
        $document = $this->makeCreateService()->create($this->createPayload([]), $this->author);

        self::assertNull($document->getSignatureLevel());
        self::assertSame([], $this->signersByUserId($document));
    }

    // --- update: lock after ON_SIGNING / SIGNED ---

    public function testUpdateSignatureLevelForbiddenWhenOnSigning(): void
    {
        $document = $this->createSignableDocument(DocumentStatus::ON_SIGNING);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_SIGNING_LOCKED);

        $this->makeUpdateService()->update($document, ['signatureLevel' => 'enhanced'], $this->author);
    }

    public function testUpdateSignersForbiddenWhenOnSigning(): void
    {
        $document = $this->createSignableDocument(DocumentStatus::ON_SIGNING);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_SIGNING_LOCKED);

        $this->makeUpdateService()->update($document, [
            'signers' => [['userId' => 20, 'order' => 1]],
        ], $this->author);
    }

    public function testUpdateSignersForbiddenWhenSigned(): void
    {
        $document = $this->createSignableDocument(DocumentStatus::SIGNED);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_SIGNING_LOCKED);

        $this->makeUpdateService()->update($document, [
            'signers' => [['userId' => 20, 'order' => 1]],
        ], $this->author);
    }

    public function testUpdateNameAllowedWhenOnSigningIfSignatureUntouched(): void
    {
        $document = $this->createSignableDocument(DocumentStatus::ON_SIGNING);

        $updated = $this->makeUpdateService()->update($document, ['name' => 'renamed'], $this->author);

        self::assertSame('renamed', $updated->getName());
        self::assertSame(SignatureLevel::SIMPLE, $updated->getSignatureLevel());
        self::assertSame([10 => 1], $this->signersByUserId($updated));
    }

    public function testUpdateReplacesSignersOnEditableDocument(): void
    {
        $document = $this->createSignableDocument(DocumentStatus::NEW);

        $updated = $this->makeUpdateService()->update($document, [
            'signatureLevel' => 'enhanced',
            'signers' => [
                ['userId' => 20, 'order' => 1],
                ['userId' => 30, 'order' => 2],
            ],
        ], $this->author);

        self::assertSame(SignatureLevel::ENHANCED, $updated->getSignatureLevel());
        self::assertSame([20 => 1, 30 => 2], $this->signersByUserId($updated));
    }

    public function testUpdateRemovingLevelWhileSignersRemainFails(): void
    {
        $document = $this->createSignableDocument(DocumentStatus::NEW);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(SpaApiError::DOCUMENT_SIGNATURE_LEVEL_REQUIRED);

        $this->makeUpdateService()->update($document, ['signatureLevel' => null], $this->author);
    }

    // --- files lock ---

    public function testAttachmentUploadForbiddenWhenOnSigning(): void
    {
        $document = $this->createSignableDocument(DocumentStatus::ON_SIGNING);
        $service = new DocumentAttachmentService(
            $this->createMock(FileRepository::class),
            new DocumentApiPresenter(),
            $this->createMock(EntityManagerInterface::class),
            sys_get_temp_dir(),
        );

        $tmpFile = tempnam(sys_get_temp_dir(), 'sig_test_');
        file_put_contents($tmpFile, 'dummy');
        $uploaded = new UploadedFile($tmpFile, 'file.pdf', null, null, true);

        try {
            $this->expectException(BadRequestHttpException::class);
            $this->expectExceptionMessage(SpaApiError::DOCUMENT_SIGNING_LOCKED);

            $service->upload($document, $uploaded);
        } finally {
            @unlink($tmpFile);
        }
    }

    // --- executors/recipients replacement keeps signers ---

    public function testReplaceRecipientsPreservesSigners(): void
    {
        $document = $this->createSignableDocument(DocumentStatus::NEW);
        $executor = new DocumentUserRecipient();
        $executor->setDocument($document);
        $executor->setUser($this->users[20]);
        $executor->setRole(DocumentRecipientRole::EXECUTOR);
        $executor->setStatus(DocumentStatus::NEW);
        $document->addUserRecipient($executor);

        $this->makeRecipientsService()->replaceRecipients($document, [30], []);

        self::assertSame([10 => 1], $this->signersByUserId($document));
        $executorIds = [];
        foreach ($document->getUserRecipients() as $recipient) {
            if ($recipient->getRole() === DocumentRecipientRole::EXECUTOR) {
                $executorIds[] = $recipient->getUser()?->getId();
            }
        }
        self::assertSame([30], $executorIds);
    }

    // --- helpers ---

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function createPayload(array $extra): array
    {
        return array_merge([
            'documentTypeId' => 5,
            'name' => 'doc with signers',
        ], $extra);
    }

    private function makeCreateService(): DocumentCreateService
    {
        $documentType = new DocumentType();
        $documentType->setName('type');
        $documentTypeRepository = $this->createMock(DocumentTypeRepository::class);
        $documentTypeRepository->method('find')->willReturn($documentType);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/document/1');

        return new DocumentCreateService(
            $documentTypeRepository,
            $this->createMock(OrganizationRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $validator,
            $this->createMock(NotificationService::class),
            $this->makeAccessService(),
            $this->makeRecipientsService(),
            $urlGenerator,
        );
    }

    private function makeUpdateService(): DocumentUpdateService
    {
        $organizationRepository = $this->createMock(OrganizationRepository::class);
        $organizationRepository->method('find')->willReturn($this->organization);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/document/1');

        return new DocumentUpdateService(
            $organizationRepository,
            $this->createMock(EntityManagerInterface::class),
            $validator,
            $this->makeAccessService(),
            $this->makeRecipientsService(),
            $this->createMock(NotificationService::class),
            $urlGenerator,
        );
    }

    private function makeRecipientsService(): DocumentRecipientsService
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findActive')->willReturnCallback(
            fn (int $id): ?User => $this->users[$id] ?? null,
        );

        return new DocumentRecipientsService(
            $userRepository,
            $this->createMock(EntityManagerInterface::class),
        );
    }

    private function makeAccessService(): DocumentAccessService
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(false);

        return new DocumentAccessService($security);
    }

    private function createSignableDocument(DocumentStatus $status): Document
    {
        $document = new Document();
        $document->setName('signable');
        $document->setCreatedBy($this->author);
        $document->setOrganizationCreator($this->organization);
        $document->setStatus($status);
        $document->setIsPublished(false);
        $document->setSignatureLevel(SignatureLevel::SIMPLE);

        $reflection = new \ReflectionProperty(Document::class, 'id');
        $reflection->setValue($document, 42);

        $signer = new DocumentUserRecipient();
        $signer->setDocument($document);
        $signer->setUser($this->users[10]);
        $signer->setRole(DocumentRecipientRole::SIGNER);
        $signer->setStatus(DocumentStatus::NEW);
        $signer->setSigningOrder(1);
        $document->addUserRecipient($signer);

        return $document;
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
     * @return array<int, int> userId => signingOrder
     */
    private function signersByUserId(Document $document): array
    {
        $signers = [];
        foreach ($document->getUserRecipients() as $recipient) {
            if ($recipient->getRole() === DocumentRecipientRole::SIGNER) {
                $signers[(int) $recipient->getUser()?->getId()] = (int) $recipient->getSigningOrder();
            }
        }
        ksort($signers);

        return $signers;
    }
}
