<?php

declare(strict_types=1);

namespace App\Tests\Controller\Document;

use App\Controller\Document\DocumentController;
use App\Controller\Document\DocumentCommentController;
use App\Controller\SpaApi\DocumentsFlow\DocumentIncomingController;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentComment;
use App\Entity\Document\DocumentUserRecipient;
use App\Entity\Organization\AbstractOrganization;
use App\Entity\Organization\Organization;
use App\Entity\User\User;
use App\Enum\Document\DocumentStatus;
use App\Repository\Document\DocumentHistoryRepository;
use App\Repository\Document\DocumentCommentRepository;
use App\Repository\Document\DocumentRepository;
use App\Repository\Organization\OrganizationRepository;
use App\Repository\User\UserRepository;
use App\Service\Notification\NotificationPublisher;
use App\Service\SpaApi\Documents\DocumentAccessService;
use App\Service\SpaApi\Documents\DocumentNotifier;
use App\Service\SpaApi\Documents\DocumentUpdateService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class DocumentPolicyTest extends KernelTestCase
{
    private User $author;
    private User $recipient;
    private Document $document;
    private DocumentAccessService $access;
    private DocumentController $controller;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->author = $this->user(1);
        $this->recipient = $this->user(2);
        $organization = $this->organization(10);
        $this->author->setOrganization($organization);
        $this->document = (new Document())
            ->setName('Original')
            ->setCreatedBy($this->author)
            ->setOrganizationCreator($organization)
            ->setStatus(DocumentStatus::NEW)
            ->setIsPublished(false);
        (new \ReflectionProperty(Document::class, 'id'))->setValue($this->document, 100);
        $this->document->addUserRecipient((new DocumentUserRecipient())
            ->setUser($this->recipient)
            ->setStatus(DocumentStatus::NEW));

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(false);
        $this->access = new DocumentAccessService($security);
        $this->controller = new DocumentController($this->access);
        $this->controller->setContainer(self::getContainer());
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn(false);
        self::getContainer()->set('security.authorization_checker', $checker);
        $this->login($this->recipient);
    }

    public function testDraftIsVisibleOnlyToAuthorOrAdminAndPublicationOpensAccess(): void
    {
        self::assertTrue($this->access->canViewDocument($this->document, $this->author));
        self::assertFalse($this->access->canViewDocument($this->document, $this->recipient));
        self::assertFalse($this->access->canCommentDocument($this->document, $this->recipient));
        self::assertFalse($this->access->presentPermissions($this->document, $this->recipient)['canChangeRecipientStatus']);
        $adminSecurity = $this->createMock(Security::class);
        $adminSecurity->method('isGranted')->with('ROLE_ADMIN')->willReturn(true);
        self::assertTrue((new DocumentAccessService($adminSecurity))->canViewDocument($this->document, $this->user(3)));

        $this->document->setIsPublished(true);
        self::assertTrue($this->access->canViewDocument($this->document, $this->recipient));
        self::assertTrue($this->access->canCommentDocument($this->document, $this->recipient));
        self::assertTrue($this->access->presentPermissions($this->document, $this->recipient)['canChangeRecipientStatus']);
        self::assertFalse($this->access->canViewDocument($this->document, $this->user(4)));
    }

    public static function draftActions(): iterable
    {
        foreach (['incoming', 'outgoing', 'download', 'status', 'history'] as $action) {
            yield $action => [$action];
        }
    }

    #[DataProvider('draftActions')]
    public function testLegacyRecipientCannotReadOrMutateDraft(string $action): void
    {
        $repository = $this->createMock(DocumentRepository::class);
        $repository->method('findOneWithRelations')->with(100)->willReturn($this->document);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $history = $this->createMock(DocumentHistoryRepository::class);
        $history->expects(self::never())->method('findByDocumentAndUserOrderByCreatedAtDesc');
        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturn($this->recipient);
        $request = Request::create('/', 'POST', ['status' => 'DONE']);

        try {
            match ($action) {
                'incoming' => $this->controller->viewIncomingDocument(100, $em, $repository),
                'outgoing' => $this->controller->viewOutgoingDocument(100, $repository),
                'download' => $this->controller->downloadDocumentFile(100, 'original', $request, $repository, '/unused', '/unused'),
                'status' => $this->controller->updateDocumentStatus(100, $request, $repository, $em),
                'history' => $this->controller->historyIncomingDocument(100, 2, $request, $repository, $history, $users),
            };
            self::fail('Unpublished document must be inaccessible to its recipient.');
        } catch (AccessDeniedException) {
            self::assertSame(DocumentStatus::NEW, $this->document->getUserRecipients()->first()->getStatus());
        }
    }

    public function testSpaRecipientCannotReadDraftOrChangeItsStatus(): void
    {
        $repository = $this->createMock(DocumentRepository::class);
        $repository->method('findOneWithRelations')->with(100)->willReturn($this->document);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        self::getContainer()->set(DocumentRepository::class, $repository);
        self::getContainer()->set('doctrine.orm.entity_manager', $em);
        $controller = self::getContainer()->get(DocumentIncomingController::class);

        self::assertSame(403, $controller->view(100, $this->recipient)->getStatusCode());
        $response = $controller->updateRecipientStatus(100, Request::create('/', 'PATCH', content: '{"status":"DONE"}'), $this->recipient);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(DocumentStatus::NEW, $this->document->getUserRecipients()->first()->getStatus());
    }

    public static function commentActions(): iterable
    {
        yield 'create' => ['create'];
        yield 'edit' => ['edit'];
        yield 'delete' => ['delete'];
    }

    #[DataProvider('commentActions')]
    public function testLegacyRecipientCannotCommentOnUnpublishedDocument(string $action): void
    {
        $comment = (new DocumentComment())->setDocument($this->document)->setAuthor($this->recipient)->setBody('Original');
        $documents = $this->createMock(DocumentRepository::class);
        $documents->method('find')->willReturn($this->document);
        $comments = $this->createMock(DocumentCommentRepository::class);
        $comments->method('find')->willReturn($comment);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('remove');
        $em->expects(self::never())->method('flush');
        $controller = new DocumentCommentController('/unused', $this->access);
        $controller->setContainer(self::getContainer());
        $request = Request::create('/', 'POST', ['body' => 'Changed']);

        try {
            if ($action === 'create') {
                $controller->create(100, $request, $documents, $em);
            } else {
                $controller->$action(1, $request, $comments, $em);
            }
            self::fail('Comments on unpublished documents must be inaccessible to recipients.');
        } catch (AccessDeniedException) {
            self::assertSame('Original', $comment->getBody());
        }
    }

    public function testSpaAuthorCannotMoveDocumentToForeignOrganization(): void
    {
        $organizations = $this->createMock(OrganizationRepository::class);
        $organizations->expects(self::never())->method('find');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $service = $this->updateService($organizations, $em, $this->access);

        try {
            $service->update($this->document, ['name' => 'Changed', 'organizationId' => 20], $this->author);
            self::fail('Foreign organization must be rejected.');
        } catch (AccessDeniedHttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
            self::assertSame(10, $this->document->getOrganizationCreator()->getId());
            self::assertSame('Original', $this->document->getName());
        }
    }

    public function testLegacyAuthorCannotMoveDocumentToForeignOrganization(): void
    {
        $this->login($this->author);
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        self::getContainer()->set('security.csrf.token_manager', $csrf);
        $repository = $this->createMock(DocumentRepository::class);
        $repository->method('findOneWithRelations')->willReturn($this->document);
        $organizations = $this->createMock(OrganizationRepository::class);
        $organizations->method('getOrganizationTree')->willReturn([]);
        $organizations->expects(self::never())->method('find');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        try {
            $this->controller->editOutgoingDocument(100, Request::create('/', 'POST', [
                '_csrf_token' => 'valid', 'name' => 'Changed', 'organization_id' => 20,
            ]), $repository, $organizations, $this->createMock(UserRepository::class), $em, $this->createMock(ValidatorInterface::class));
            self::fail('Foreign organization must be rejected.');
        } catch (AccessDeniedException) {
            self::assertSame(10, $this->document->getOrganizationCreator()->getId());
            self::assertSame('Original', $this->document->getName());
        }
    }

    public static function permittedOrganizationChanges(): iterable
    {
        yield 'own organization' => [false, 10, 10];
        yield 'author transferred, organization unchanged' => [false, 20, 10];
        yield 'author moves to own new organization' => [false, 20, 20];
        yield 'administrator moves to another organization' => [true, 10, 30];
    }

    #[DataProvider('permittedOrganizationChanges')]
    public function testPermittedOrganizationEditsArePreserved(bool $admin, int $userOrganizationId, int $targetId): void
    {
        $this->author->setOrganization($this->organization($userOrganizationId));
        $target = $this->organization($targetId);
        $organizations = $this->createMock(OrganizationRepository::class);
        $organizations->method('find')->with($targetId)->willReturn($target);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn($admin);
        $service = $this->updateService($organizations, $em, new DocumentAccessService($security));

        $service->update($this->document, ['name' => 'Changed', 'organizationId' => $targetId], $this->author);

        self::assertSame($target, $this->document->getOrganizationCreator());
        self::assertSame('Changed', $this->document->getName());
    }

    private function updateService(OrganizationRepository $organizations, EntityManagerInterface $em, DocumentAccessService $access): DocumentUpdateService
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        return new DocumentUpdateService($organizations, $em, $validator, $access, new DocumentNotifier(new NotificationPublisher($bus)));
    }

    private function login(User $user): void
    {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
    }

    private function user(int $id): User
    {
        $user = (new User())->setLogin('test-' . $id);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function organization(int $id): Organization
    {
        $organization = new Organization();
        (new \ReflectionProperty(AbstractOrganization::class, 'id'))->setValue($organization, $id);

        return $organization;
    }
}
