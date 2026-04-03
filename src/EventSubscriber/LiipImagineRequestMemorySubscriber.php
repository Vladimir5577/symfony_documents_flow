<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Повышает memory_limit только для HTTP-запросов к LiipImagine (/media/cache/…),
 * чтобы декодирование крупных JPEG не упиралось в дефолтный лимит PHP, не трогая остальные воркеры/запросы.
 */
final class LiipImagineRequestMemorySubscriber implements EventSubscriberInterface
{
    private const MEMORY_LIMIT = '512M';

    private const MAX_EXECUTION_TIME = '120';

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 512]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/media/cache/')) {
            return;
        }

        ini_set('memory_limit', self::MEMORY_LIMIT);
        ini_set('max_execution_time', self::MAX_EXECUTION_TIME);
    }
}
