<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Post\Post;
use App\Service\SpaApi\Post\PostImagePreviewUrlGenerator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Обложка публикации для твиговых шаблонов.
 *
 * Шаблоны собирали путь руками — src="/uploads/posts/{id}/{name}" — то есть
 * ходили напрямую в приватное хранилище, которое раздавалось статикой в обход
 * Symfony. Теперь путь строится тем же генератором превью, что использует SPA:
 * ссылка ведёт в кэш LiipImagine (/media/cache), а не в каталог с оригиналами.
 *
 * Побочный эффект, и он желаемый: для форматов, которые генератор не считает
 * изображениями, вернётся null, и шаблон покажет заглушку вместо ссылки на
 * оригинал.
 */
final class PostCoverExtension extends AbstractExtension
{
    public function __construct(
        private readonly PostImagePreviewUrlGenerator $previewUrlGenerator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('post_cover_url', [$this, 'getPostCoverUrl']),
        ];
    }

    public function getPostCoverUrl(?Post $post): ?string
    {
        if (!$post instanceof Post) {
            return null;
        }

        return $this->previewUrlGenerator->getCoverPreviewUrl($post);
    }
}
