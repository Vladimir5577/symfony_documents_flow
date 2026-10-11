<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class PrivateFileResponseSubscriber implements EventSubscriberInterface
{
    private const ATTACHMENT_ROUTES = [
        'app_my_files_download',
        'app_post_file_download',
        'app_post_cover',
        'spa_api_posts_file_download',
        'spa_api_posts_cover',
        'spa_api_purchases_files_download',
    ];

    public function __construct(
        #[Autowire('%private_upload_dir%')]
        private readonly string $uploadDir,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', 16]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $request = $event->getRequest();
        if (!$event->isMainRequest()) {
            return;
        }

        if (in_array($request->attributes->get('_route'), self::ATTACHMENT_ROUTES, true)) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $disposition = $response->headers->get('Content-Disposition');
            if ($disposition !== null) {
                $contentType = $response->headers->get('Content-Type');
                if ($contentType === null && $response instanceof BinaryFileResponse) {
                    $contentType = $response->getFile()->getMimeType();
                }
                $mime = strtolower(trim(explode(';', $contentType ?? '')[0]));
                if (in_array($mime, ['text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/xml', 'text/xml'], true)
                    || preg_match('/\.(?:html?|xhtml|svg|xml)(?:[";]|$)/i', rawurldecode($disposition))) {
                    $response->headers->set('Content-Disposition', preg_replace('/^inline\b/i', 'attachment', $disposition));
                    $response->headers->set('Content-Type', 'application/octet-stream');
                }
            }
        }

        if (!$response instanceof BinaryFileResponse) {
            return;
        }

        // Only the local FastCGI configuration can enable acceleration, not an HTTP header.
        $request->headers->remove('X-Sendfile-Type');
        $request->headers->remove('X-Accel-Mapping');
        $root = realpath($this->uploadDir);
        $path = $response->getFile()->getRealPath();
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            return;
        }

        $response->setPrivate();
        if ($request->server->get('APP_X_ACCEL_REDIRECT') !== '1') {
            return;
        }

        // BinaryFileResponse keeps its normal PHP streaming fallback outside Nginx.
        BinaryFileResponse::trustXSendfileTypeHeader();
        $request->headers->set('X-Sendfile-Type', 'X-Accel-Redirect');
        $request->headers->set('X-Accel-Mapping', $root . DIRECTORY_SEPARATOR . '=/uploads/');
    }
}
