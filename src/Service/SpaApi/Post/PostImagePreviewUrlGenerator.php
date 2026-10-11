<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Post;

use App\Entity\Post\File as PostFile;
use App\Entity\Post\Post;
use App\Security\Voter\PostVoter;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Изображения постов выдаются только после проверки видимости объявления.
 */
final class PostImagePreviewUrlGenerator
{
    public const URL_TTL = 3600;

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UriSigner $uriSigner,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function getCoverPreviewUrl(Post $post): ?string
    {
        return $this->getCoverImageUrl($post, true);
    }

    public function getCoverImageUrl(Post $post, bool $thumbnail = false): ?string
    {
        $name = $post->getCoverImageName();
        if ($name === null || $name === '' || !$this->isImageName($name)) {
            return null;
        }

        return $this->sign($post, 'spa_api_post_images_cover', [
            'id' => $post->getId(),
            'thumbnail' => $thumbnail ? 1 : 0,
        ]);
    }

    public function getFilePreviewUrl(PostFile $file): ?string
    {
        $filePath = $file->getFilePath();
        if ($filePath === null || $filePath === '' || !$this->isImageName($filePath)) {
            return null;
        }

        return $this->sign($file->getPost(), 'spa_api_post_images_file', [
            'fileId' => $file->getId(),
        ]);
    }

    public function isImageName(string $name): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }

    private function sign(?Post $post, string $route, array $parameters): string
    {
        if (!$this->authorizationChecker->isGranted(PostVoter::VIEW, $post)) {
            throw new AccessDeniedException();
        }

        return $this->uriSigner->sign(
            $this->urlGenerator->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL),
            time() + self::URL_TTL,
        );
    }
}
