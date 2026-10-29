<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Post;

use App\Entity\Post\File as PostFile;
use App\Repository\Post\FileRepository;
use App\Repository\Post\PostRepository;
use App\Service\SpaApi\Post\PostImagePreviewUrlGenerator;
use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Liip\ImagineBundle\Model\FileBinary;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/spa/api/post-images')]
final class PostImageController extends AbstractController
{
    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly FileRepository $fileRepository,
        private readonly UriSigner $uriSigner,
        #[Autowire(service: 'liip_imagine.filter.manager')]
        private readonly FilterManager $filterManager,
        private readonly Filesystem $filesystem,
        #[Autowire('%private_upload_dir_posts%')]
        private readonly string $uploadDirPosts,
        #[Autowire('%kernel.cache_dir%/post-images')]
        private readonly string $thumbnailDir,
    ) {
    }

    #[Route('/{id}/cover', name: 'spa_api_post_images_cover', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function cover(int $id, Request $request): BinaryFileResponse
    {
        $expiration = $this->checkSignature($request);
        $post = $this->postRepository->find($id);
        if ($post === null || $post->getDeletedAt() !== null || !$post->getCoverImageName()) {
            throw $this->createNotFoundException();
        }

        return $this->imageResponse(
            $post->getId() . '/' . $post->getCoverImageName(),
            $request->query->getBoolean('thumbnail'),
            $expiration,
        );
    }

    #[Route('/files/{fileId}', name: 'spa_api_post_images_file', requirements: ['fileId' => '\d+'], methods: ['GET'])]
    public function postFile(int $fileId, Request $request): BinaryFileResponse
    {
        $expiration = $this->checkSignature($request);
        $file = $this->fileRepository->find($fileId);
        if (!$file instanceof PostFile || !$file->getPost() || $file->getPost()->getDeletedAt() !== null || !$file->getFilePath()) {
            throw $this->createNotFoundException();
        }

        $path = $file->getFilePath();

        return $this->imageResponse(
            str_contains($path, '/') ? $path : $file->getPost()->getId() . '/' . $path,
            true,
            $expiration,
        );
    }

    private function checkSignature(Request $request): int
    {
        $query = $request->query->all();
        $expiration = $query['_expiration'] ?? null;
        if (!is_string($query['_hash'] ?? null) || !is_string($expiration)
            || !ctype_digit($expiration) || (int) $expiration <= time()
            || !$this->uriSigner->checkRequest($request)) {
            throw new AccessDeniedHttpException();
        }

        return (int) $expiration;
    }

    private function imageResponse(string $relativePath, bool $thumbnail, int $expiration): BinaryFileResponse
    {
        $root = realpath($this->uploadDirPosts);
        $path = realpath($this->uploadDirPosts . '/' . $relativePath);
        if ($root === false || $path === false || !is_file($path)
            || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw $this->createNotFoundException();
        }

        $mime = (new File($path))->getMimeType();
        $format = match ($mime) {
            'image/jpeg' => 'jpeg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
        if ($format === null) {
            throw $this->createNotFoundException();
        }

        if ($thumbnail) {
            $key = hash('sha256', $path . ':' . filemtime($path) . ':' . filesize($path));
            $cachedPath = $this->thumbnailDir . '/' . $key . '.' . $format;
            if (!is_file($cachedPath)) {
                $image = $this->filterManager->applyFilter(new FileBinary($path, $mime, $format), 'post_image_preview');
                // dumpFile writes atomically; this cache is never exposed by the web resolver.
                $this->filesystem->dumpFile($cachedPath, $image->getContent());
            }
            $path = $cachedPath;
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'image.' . $format);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPrivate();
        $response->setMaxAge(max(0, min(PostImagePreviewUrlGenerator::URL_TTL, $expiration - time())));
        $response->headers->addCacheControlDirective('must-revalidate');

        return $response;
    }
}
