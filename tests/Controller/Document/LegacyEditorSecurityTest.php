<?php

declare(strict_types=1);

namespace App\Tests\Controller\Document;

use App\Controller\Document\DocumentCreateFromTemplateController;
use App\Entity\Document\Document;
use App\Entity\User\User;
use App\Service\Document\Convertor\DocxToPdfConvertorService;
use App\Service\OnlyOffice\LegacyEditorSession;
use App\Service\OnlyOffice\OnlyOfficeCallback;
use App\Service\OnlyOffice\OnlyOfficeJwt;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class LegacyEditorSecurityTest extends KernelTestCase
{
    private string $directory;
    private User $user;
    private Document $document;
    private LegacyEditorSession $sessions;
    private ArrayAdapter $cache;
    private OnlyOfficeJwt $jwt;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pulse-editor-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        mkdir($this->directory . '/public');
        mkdir($this->directory . '/private');
        file_put_contents($this->directory . '/private/source.docx', 'original');
        $this->user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->user, 1);
        $this->document = (new Document())->setCreatedBy($this->user)->setOriginalFile('source.docx');
        (new \ReflectionProperty(Document::class, 'id'))->setValue($this->document, 7);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(fn (string $class) => $class === User::class ? $this->user : $this->document);
        $this->cache = new ArrayAdapter();
        $this->sessions = new LegacyEditorSession($this->cache, $em, $this->directory . '/private', $this->directory);
        $this->jwt = new OnlyOfficeJwt('test-onlyoffice-secret');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/private/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory . '/private');
        rmdir($this->directory . '/public');
        rmdir($this->directory);
        parent::tearDown();
    }

    public static function forbiddenSources(): iterable
    {
        yield ['file:///etc/passwd'];
        yield ['http://127.0.0.1/cache/files/test.docx'];
        yield ['http://169.254.169.254/cache/files/test.docx'];
        yield ['https://office.example.evil/cache/files/test.docx'];
        yield ['https://office.example:444/cache/files/test.docx'];
        yield ['https://office.example/cache/files/../../private'];
        yield ['https://office.example/cache/files/%2e%2e/private'];
        yield ['https://user@office.example/cache/files/test.docx'];
    }

    #[DataProvider('forbiddenSources')]
    public function testSignedCallbackRejectsForbiddenSourceBeforeNetwork(string $source): void
    {
        $session = $this->sessions->create($this->user, $this->document, '', false);
        $response = $this->callback($session, ['key' => $session['key'], 'status' => 2, 'url' => $source], true);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('original', file_get_contents($this->directory . '/private/source.docx'));
    }

    public function testUnsignedAndWrongKeyCallbacksCannotWrite(): void
    {
        $session = $this->sessions->create($this->user, $this->document, '', false);
        $data = ['key' => $session['key'], 'status' => 2, 'url' => 'file:///etc/passwd'];
        self::assertSame(403, $this->callback($session, $data, false)->getStatusCode());
        $data['key'] = 'other-session';
        self::assertSame(403, $this->callback($session, $data, true)->getStatusCode());
        self::assertSame(['.', '..', 'source.docx'], scandir($this->directory . '/private'));
    }

    public function testAuthorizedCallbackUsesServerDestinationAndSignedPayload(): void
    {
        $path = $this->directory . '/private/fixture.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE));
        $zip->addFromString('word/document.xml', '<document/>');
        $zip->close();
        $docx = file_get_contents($path);
        $session = $this->sessions->create($this->user, $this->document, '', false);
        $saveId = $this->sessions->beginSave($session['id']);
        self::assertFalse($this->sessions->isSaved($session['id'], $saveId));
        $data = ['key' => $session['key'], 'status' => 6, 'url' => 'https://office.example/cache/files/edited.docx', 'userdata' => $saveId];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($docx) {
            self::assertSame('http://onlyoffice:80/cache/files/edited.docx', $url);
            self::assertSame(0, $options['max_redirects']);
            return new MockResponse($docx);
        });
        $callback = new OnlyOfficeCallback($this->jwt, $http, 'https://office.example', 'http://onlyoffice:80');
        $controller = new DocumentCreateFromTemplateController($this->sessions, $this->jwt);
        $controller->setContainer(new Container());
        $converter = $this->createMock(DocxToPdfConvertorService::class);
        $converter->expects(self::once())->method('convertDocxToPdf')->with($this->sessions->path('source.docx'));
        $request = Request::create('/save_docx?session=' . $session['id'] . '&filename=attacker.php', 'POST', [], [], [], [], json_encode([
            'url' => 'file:///etc/passwd', 'token' => $this->jwt->encode($data),
        ]));
        self::assertSame(200, $controller->saveDocx($request, $converter, $callback)->getStatusCode());
        self::assertSame($docx, file_get_contents($this->directory . '/private/source.docx'));
        self::assertFileDoesNotExist($this->directory . '/private/attacker.php');
        self::assertTrue($this->sessions->isSaved($session['id'], $saveId));
    }

    public function testRedirectIsNotFollowed(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls) {
            ++$calls;
            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: http://169.254.169.254/']]);
        });
        $callback = new OnlyOfficeCallback($this->jwt, $http, 'https://office.example', 'http://onlyoffice:80');
        self::assertNull($callback->download('https://office.example/cache/files/document.docx'));
        self::assertSame(1, $calls);
    }

    public function testHeaderJwtUsesSignedPayload(): void
    {
        $payload = ['key' => 'document', 'status' => 2];
        $request = Request::create('/save_docx', 'POST', [], [], [], [], '{"status":6}');
        $request->headers->set('Authorization', 'Bearer ' . $this->jwt->encode(['payload' => $payload]));
        $callback = new OnlyOfficeCallback($this->jwt, new MockHttpClient(), 'https://office.example', 'http://onlyoffice:80');
        self::assertSame($payload, $callback->data($request));
    }

    public function testExpiredOrRevokedSessionCannotRead(): void
    {
        $session = $this->sessions->create($this->user, $this->document, '', false);
        self::assertNotNull($this->sessions->get($session['id']));
        $this->user->setDeletedAt(new \DateTimeImmutable());
        self::assertNull($this->sessions->get($session['id']));
        $this->user->setDeletedAt(null);
        $session['expires'] = time() - 1;
        $item = $this->cache->getItem('legacy_editor_' . $session['id']);
        $item->set($session);
        $this->cache->save($item);
        self::assertNull($this->sessions->get($session['id']));
    }

    public function testPublicDestinationIsRejected(): void
    {
        $sessions = new LegacyEditorSession($this->cache, $this->createMock(EntityManagerInterface::class), $this->directory . '/public', $this->directory);
        $this->expectException(ServiceUnavailableHttpException::class);
        $sessions->path('document.docx');
    }

    public function testEditorDoesNotOpenWithoutSigningSecret(): void
    {
        $controller = new DocumentCreateFromTemplateController($this->sessions, new OnlyOfficeJwt(null));
        $documents = $this->createMock(\App\Repository\Document\DocumentRepository::class);
        $documents->expects(self::never())->method('find');
        $access = new \App\Service\SpaApi\Documents\DocumentAccessService(new \Symfony\Bundle\SecurityBundle\Security(new Container()));
        $this->expectException(ServiceUnavailableHttpException::class);
        $controller->editDocx(new Request(), $documents, $access, $this->directory . '/private', $this->directory . '/private', 'https://office.example');
    }

    public function testForcesaveRequiresCsrfBeforeSendingCommand(): void
    {
        $controller = new DocumentCreateFromTemplateController($this->sessions, $this->jwt);
        $tokens = $this->createMock(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class);
        $tokens->method('isTokenValid')->willReturn(false);
        $container = new Container();
        $container->set('security.csrf.token_manager', $tokens);
        $controller->setContainer($container);
        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $controller->triggerForcesave(Request::create('/trigger_forcesave', 'POST', [], [], [], [], '{"key":"document"}'), 'http://onlyoffice:80');
    }

    private function callback(array $session, array $data, bool $signed): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $http = new MockHttpClient(static function () { self::fail('Forbidden callback reached the network.'); });
        $callback = new OnlyOfficeCallback($this->jwt, $http, 'https://office.example', 'http://onlyoffice:80');
        $controller = new DocumentCreateFromTemplateController($this->sessions, $this->jwt);
        $controller->setContainer(new Container());
        $converter = $this->createMock(DocxToPdfConvertorService::class);
        $converter->expects(self::never())->method('convertDocxToPdf');
        if ($signed) {
            $data['token'] = $this->jwt->encode($data);
        }
        $request = Request::create('/save_docx?session=' . $session['id'] . '&filename=attacker.php', 'POST', [], [], [], [], json_encode($data));

        return $controller->saveDocx($request, $converter, $callback);
    }
}
