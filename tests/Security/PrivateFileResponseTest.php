<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\EventSubscriber\PrivateFileResponseSubscriber;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class PrivateFileResponseTest extends KernelTestCase
{
    private string $directory;
    private string $file;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->directory = sys_get_temp_dir() . '/pulse-files-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->file = $this->directory . '/attachment.txt';
        file_put_contents($this->file, 'private file');
        self::getContainer()->set(PrivateFileResponseSubscriber::class, new PrivateFileResponseSubscriber($this->directory));
    }

    protected function tearDown(): void
    {
        if (isset($this->file) && is_file($this->file)) {
            unlink($this->file);
        }
        if (isset($this->directory) && is_dir($this->directory)) {
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function testNginxGetsInternalRedirectAfterControllerResponse(): void
    {
        $request = Request::create('/my_files/download/1');
        $request->server->set('APP_X_ACCEL_REDIRECT', '1');
        $response = $this->dispatch($request);

        self::assertSame('/uploads/attachment.txt', rawurldecode($response->headers->get('X-Accel-Redirect')));
        self::assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
    }

    public function testClientCannotSelectAnAccelerationTarget(): void
    {
        $request = Request::create('/my_files/download/1');
        $request->headers->set('X-Sendfile-Type', 'X-Accel-Redirect');
        $request->headers->set('X-Accel-Mapping', $this->directory . '/=/private/');
        $request->headers->set('APP-X-ACCEL-REDIRECT', '1');
        $response = $this->dispatch($request);

        self::assertFalse($response->headers->has('X-Accel-Redirect'));
        self::assertSame((string) strlen('private file'), $response->headers->get('Content-Length'));
    }

    public function testFilesOutsideUploadDirectoryAreNotRedirected(): void
    {
        $request = Request::create('/my_files/download/1');
        $request->server->set('APP_X_ACCEL_REDIRECT', '1');

        self::assertFalse($this->dispatch($request, __FILE__)->headers->has('X-Accel-Redirect'));
    }

    public function testActiveAttachmentCannotBeRenderedInline(): void
    {
        foreach (['text/html; charset=UTF-8', 'image/svg+xml', 'application/xhtml+xml'] as $mime) {
            $request = Request::create('/spa/api/purchases/1/files/1/download?inline=1');
            $request->attributes->set('_route', 'spa_api_purchases_files_download');
            $response = new Response('<svg/>', 200, [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline; filename="attachment.txt"',
            ]);
            self::getContainer()->get('event_dispatcher')->dispatch(
                new ResponseEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response),
                KernelEvents::RESPONSE,
            );

            self::assertSame('attachment; filename="attachment.txt"', $response->headers->get('Content-Disposition'));
            self::assertSame('application/octet-stream', $response->headers->get('Content-Type'));
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        }
    }

    public function testAttachmentExtensionAlsoPreventsInlineRendering(): void
    {
        $request = Request::create('/my_files/download/1?inline=1');
        $request->attributes->set('_route', 'app_my_files_download');
        $response = new BinaryFileResponse($this->file);
        $response->setContentDisposition('inline', 'attachment.svg');
        $response->headers->set('Content-Type', 'text/plain');
        self::getContainer()->get('event_dispatcher')->dispatch(
            new ResponseEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response),
            KernelEvents::RESPONSE,
        );

        self::assertSame('attachment; filename=attachment.svg', $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testSafePreviewStaysInline(): void
    {
        $request = Request::create('/my_files/download/1?inline=1');
        $request->attributes->set('_route', 'app_my_files_download');
        $response = new Response('pdf', 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="attachment.pdf"',
        ]);
        self::getContainer()->get('event_dispatcher')->dispatch(
            new ResponseEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response),
            KernelEvents::RESPONSE,
        );

        self::assertSame('inline; filename="attachment.pdf"', $response->headers->get('Content-Disposition'));
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    private function dispatch(Request $request, ?string $file = null): BinaryFileResponse
    {
        $response = new BinaryFileResponse($file ?? $this->file);
        $response->setContentDisposition('attachment', 'attachment.txt');
        self::getContainer()->get('event_dispatcher')->dispatch(
            new ResponseEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response),
            KernelEvents::RESPONSE,
        );

        return $response;
    }
}
