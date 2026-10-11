<?php

declare(strict_types=1);

namespace App\Tests\Controller\Document;

use App\Controller\Document\DocumentCreateFromTemplateController;
use App\Controller\Document\DocumentFileController;
use App\Controller\Document\DocumentSignController;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentUserRecipient;
use App\Entity\Document\File;
use App\Entity\User\User;
use App\Enum\Document\DocumentRecipientRole;
use App\Repository\Document\DocumentRepository;
use App\Repository\Document\FileRepository;
use App\Service\Document\Convertor\DocxToPdfConvertorService;
use App\Service\Document\FileUploadService;
use App\Service\SpaApi\Documents\DocumentAccessService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class LegacyDocumentAccessTest extends KernelTestCase
{
    private Container $controllerContainer;
    private DocumentAccessService $accessService;
    private string $directory;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->accessService = new DocumentAccessService($container->get(Security::class));
        $this->directory = sys_get_temp_dir() . '/pulse-document-access-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        file_put_contents($this->directory . '/document.pdf', 'original document contents');

        $sessionRequest = new Request();
        $sessionRequest->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($sessionRequest);

        $this->controllerContainer = new Container();
        $this->controllerContainer->set('security.token_storage', $container->get('security.token_storage'));
        $this->controllerContainer->set('security.csrf.token_manager', $container->get('security.csrf.token_manager'));
        $this->controllerContainer->setParameter('private_upload_dir_documents_originals', $this->directory);
        $this->controllerContainer->set('parameter_bag', new ContainerBag($this->controllerContainer));
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            unlink($this->directory . '/document.pdf');
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public static function forbiddenActions(): iterable
    {
        yield 'stranger cannot download' => ['download', null, true];
        yield 'recipient cannot download draft' => ['download', DocumentRecipientRole::RECIPIENT, false];
        yield 'stranger cannot upload' => ['upload', null, true];
        yield 'recipient cannot upload' => ['upload', DocumentRecipientRole::RECIPIENT, true];
        yield 'stranger cannot delete' => ['delete', null, true];
        yield 'recipient cannot delete with valid token' => ['delete', DocumentRecipientRole::RECIPIENT, true];
        yield 'stranger cannot sign' => ['sign', null, true];
        yield 'recipient cannot sign' => ['sign', DocumentRecipientRole::RECIPIENT, true];
        yield 'executor cannot sign draft' => ['sign', DocumentRecipientRole::EXECUTOR, false];
        yield 'stranger cannot open signing form' => ['signForm', null, true];
        yield 'recipient cannot open signing form' => ['signForm', DocumentRecipientRole::RECIPIENT, true];
        yield 'stranger cannot attach docx' => ['attachDocx', null, true];
        yield 'recipient cannot attach docx' => ['attachDocx', DocumentRecipientRole::RECIPIENT, true];
    }

    #[DataProvider('forbiddenActions')]
    public function testForbiddenActionDoesNotChangeDocumentOrFiles(
        string $action,
        ?DocumentRecipientRole $role,
        bool $published,
    ): void {
        $user = $this->login(2);
        $document = $this->document($this->user(1), $user, $role, $published);
        $documentRepository = $this->createMock(DocumentRepository::class);
        $documentRepository->method('find')->willReturn($document);
        $documentRepository->method('findOneWithRelations')->willReturn($document);
        $fileRepository = $this->createMock(FileRepository::class);
        $fileRepository->method('find')->willReturn((new File())->setDocument($document)->setFilePath('./document.pdf'));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('remove');
        $entityManager->expects(self::never())->method('flush');
        $uploadService = $this->createMock(FileUploadService::class);
        $uploadService->expects(self::never())->method('deleteUpdatedFile');
        $convertor = $this->createMock(DocxToPdfConvertorService::class);
        $convertor->expects(self::never())->method('convertDocxToPdf');
        $hash = hash_file('sha256', $this->directory . '/document.pdf');

        $fileController = new DocumentFileController($this->accessService);
        $fileController->setContainer($this->controllerContainer);
        $signController = new DocumentSignController($this->accessService);
        $signController->setContainer($this->controllerContainer);
        $templateController = new DocumentCreateFromTemplateController(
            self::getContainer()->get(\App\Service\OnlyOffice\LegacyEditorSession::class),
            self::getContainer()->get(\App\Service\OnlyOffice\OnlyOfficeJwt::class),
        );
        $templateController->setContainer($this->controllerContainer);
        $tokens = $this->controllerContainer->get('security.csrf.token_manager');

        try {
            switch ($action) {
                case 'download':
                    $fileController->download(7, new Request(), $fileRepository);
                    break;
                case 'upload':
                    $fileController->new(new Request(), 10, $documentRepository, $entityManager);
                    break;
                case 'delete':
                    $request = new Request([], ['_token' => $tokens->getToken('document_file_delete_7')->getValue()]);
                    $fileController->delete(7, $request, $fileRepository, $documentRepository, $entityManager);
                    break;
                case 'sign':
                    $request = Request::create('/executors_signature', 'POST', [], [], [], [], json_encode(['id' => 10, 'page' => 1, 'x' => 0, 'y' => 0]));
                    $signController->executorsSignature($request, $documentRepository, $this->directory, $this->directory);
                    break;
                case 'signForm':
                    $signController->signAndSaveDocument(10, $documentRepository, $uploadService);
                    break;
                case 'attachDocx':
                    $request = new Request([], [
                        'document_id' => 10,
                        'from_template' => '1',
                        'filename' => 'document.pdf',
                        '_token' => $tokens->getToken('save_file_to_document')->getValue(),
                    ]);
                    $templateController->saveFileFromTemplateToDocument(
                        $request, $documentRepository, $entityManager, $uploadService, $convertor,
                        $this->accessService, $this->directory,
                    );
                    break;
            }
            self::fail('The document action must deny access.');
        } catch (AccessDeniedException) {
            self::assertSame($hash, hash_file('sha256', $this->directory . '/document.pdf'));
            self::assertSame('document.docx', $document->getOriginalFile());
            self::assertSame('document.pdf', $document->getUpdatedFile());
            self::assertSame(['.', '..', 'document.pdf'], scandir($this->directory));
        }
    }

    public static function allowedReaders(): iterable
    {
        yield 'author draft' => [1, false, false];
        yield 'recipient published' => [2, false, true];
        yield 'administrator draft' => [3, true, false];
    }

    #[DataProvider('allowedReaders')]
    public function testAuthorizedReaderCanDownload(int $userId, bool $admin, bool $published): void
    {
        $user = $this->login($userId, $admin);
        $document = $this->document($this->user(1), $user, DocumentRecipientRole::RECIPIENT, $published);
        $file = (new File())->setDocument($document)->setFilePath('./document.pdf');
        $repository = $this->createMock(FileRepository::class);
        $repository->method('find')->willReturn($file);
        $controller = new DocumentFileController($this->accessService);
        $controller->setContainer($this->controllerContainer);

        $response = $controller->download(7, new Request(), $repository);

        self::assertSame(200, $response->getStatusCode());
        ob_start();
        $response->sendContent();
        self::assertSame('original document contents', ob_get_clean());
    }

    public function testExecutorCanOpenSigningFormEvenWithAdditionalRecipientRole(): void
    {
        $user = $this->login(2);
        $document = $this->document($this->user(1), $user, DocumentRecipientRole::RECIPIENT, true);
        $document->addUserRecipient((new DocumentUserRecipient())->setUser($user)->setRole(DocumentRecipientRole::EXECUTOR));
        $repository = $this->createMock(DocumentRepository::class);
        $repository->method('findOneWithRelations')->willReturn($document);
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/document/10/file');
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->with('document/sign_document.html.twig', [
            'active_tab' => 'incoming_documents',
            'file_url' => '/document/10/file',
            'id' => 10,
        ])->willReturn('signing form');
        $this->controllerContainer->set('router', $router);
        $this->controllerContainer->set('twig', $twig);
        $controller = new DocumentSignController($this->accessService);
        $controller->setContainer($this->controllerContainer);

        $response = $controller->signAndSaveDocument(10, $repository, $this->createMock(FileUploadService::class));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('signing form', $response->getContent());
    }

    public static function attachmentContents(): iterable
    {
        yield 'HTML' => ['<!DOCTYPE html><html><body>marker</body></html>', false];
        yield 'SVG' => ['<svg xmlns="http://www.w3.org/2000/svg"><text>marker</text></svg>', false];
        yield 'PDF' => ["%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF", true];
    }

    #[DataProvider('attachmentContents')]
    public function testDocumentAndCommentInlineDownloadUseSafeMimeTypes(string $content, bool $inline): void
    {
        $user = $this->login(1);
        $document = $this->document($user, $user, null, false);
        file_put_contents($this->directory . '/document.pdf', $content);
        $file = (new File())->setDocument($document)->setFilePath('./document.pdf');
        $repository = $this->createMock(FileRepository::class);
        $repository->method('find')->willReturn($file);
        $controller = new DocumentFileController($this->accessService);
        $controller->setContainer($this->controllerContainer);
        $response = $controller->download(7, new Request(['inline' => '1']), $repository);
        self::assertStringStartsWith($inline ? 'inline;' : 'attachment;', $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame($inline ? 'application/pdf' : 'application/octet-stream', $response->headers->get('Content-Type'));

        mkdir($this->directory . '/10');
        file_put_contents($this->directory . '/10/comment.pdf', $content);
        try {
            $comment = (new \App\Entity\Document\DocumentComment())->setDocument($document)->setAuthor($user);
            $commentFile = (new \App\Entity\Document\DocumentCommentFile())->setComment($comment)
                ->setStorageKey('comment.pdf')->setFilename('comment.pdf')->setContentType('application/pdf');
            $comments = $this->createMock(\App\Repository\Document\DocumentCommentFileRepository::class);
            $comments->method('find')->willReturn($commentFile);
            $commentController = new \App\Controller\Document\DocumentCommentController($this->directory, $this->accessService);
            $commentController->setContainer($this->controllerContainer);
            $response = $commentController->download(7, new Request(['inline' => '1']), $comments);
            self::assertStringStartsWith($inline ? 'inline;' : 'attachment;', $response->headers->get('Content-Disposition'));
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            self::assertSame($inline ? 'application/pdf' : 'application/octet-stream', $response->headers->get('Content-Type'));
        } finally {
            unlink($this->directory . '/10/comment.pdf');
            rmdir($this->directory . '/10');
        }
    }

    public function testAuthorUploadWithoutCsrfDoesNotPersistFiles(): void
    {
        $user = $this->login(1);
        $document = $this->document($user, $user, null, false);
        $repository = $this->createMock(DocumentRepository::class);
        $repository->method('find')->willReturn($document);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $controller = new DocumentFileController($this->accessService);
        $controller->setContainer($this->controllerContainer);
        $this->expectException(AccessDeniedException::class);
        $controller->new(new Request(), 10, $repository, $em);
    }

    public function testExecutorSignatureWithoutCsrfDoesNotChangeFile(): void
    {
        $user = $this->login(2);
        $document = $this->document($this->user(1), $user, DocumentRecipientRole::EXECUTOR, true);
        $repository = $this->createMock(DocumentRepository::class);
        $repository->method('findOneWithRelations')->willReturn($document);
        $controller = new DocumentSignController($this->accessService);
        $controller->setContainer($this->controllerContainer);
        $request = Request::create('/executors_signature', 'POST', [], [], [], [], '{"id":10,"page":1,"x":0,"y":0}');
        try {
            $controller->executorsSignature($request, $repository, $this->directory, $this->directory);
            self::fail('Unsigned session request must be rejected.');
        } catch (AccessDeniedException) {
            self::assertSame('original document contents', file_get_contents($this->directory . '/document.pdf'));
        }
    }

    private function login(int $id, bool $admin = false): User
    {
        $user = $this->user($id);
        $roles = $admin ? ['ROLE_ADMIN'] : ['ROLE_USER'];
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $roles));

        return $user;
    }

    private function user(int $id): User
    {
        $user = (new User())->setLogin('document-access-' . $id)->setFirstname('First')->setLastname('Last');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function document(User $creator, User $user, ?DocumentRecipientRole $role, bool $published): Document
    {
        $document = (new Document())->setCreatedBy($creator)->setIsPublished($published)
            ->setOriginalFile('document.docx')->setUpdatedFile('document.pdf');
        (new \ReflectionProperty(Document::class, 'id'))->setValue($document, 10);
        if ($role !== null) {
            $document->addUserRecipient((new DocumentUserRecipient())->setUser($user)->setRole($role));
        }

        return $document;
    }
}
