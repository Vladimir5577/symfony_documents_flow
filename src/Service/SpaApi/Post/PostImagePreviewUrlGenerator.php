<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Post;

use App\Entity\Post\File as PostFile;
use App\Entity\Post\Post;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Изображения постов выдаются только после проверки видимости объявления.
 */
final class PostImagePreviewUrlGenerator
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getCoverPreviewUrl(Post $post): ?string
    {
        $name = $post->getCoverImageName();
        if ($name === null || $name === '' || !$this->isImageName($name)) {
            return null;
        }

        return $this->urlGenerator->generate('spa_api_posts_cover', ['id' => $post->getId()]);
    }

    public function getFilePreviewUrl(PostFile $file): ?string
    {
        $filePath = $file->getFilePath();
        if ($filePath === null || $filePath === '' || !$this->isImageName($filePath)) {
            return null;
        }

        return $this->urlGenerator->generate('spa_api_posts_file_download', [
            'fileId' => $file->getId(),
            'inline' => true,
        ]);
    }

    public function isImageName(string $name): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }

}
