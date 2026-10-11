<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\SpaApi\Post\PostImageController;
use App\Entity\Post\File as PostFile;
use App\Entity\Post\Post;
use App\Entity\User\User;
use App\Repository\Post\FileRepository;
use App\Repository\Post\PostRepository;
use App\Service\SpaApi\Post\PostImagePreviewUrlGenerator;
use App\Service\SpaApi\Post\PostResponseFormatter;
use Imagine\Image\Box;
use Imagine\Image\ImagineInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class PostImageAccessTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $directory;
    private Post $post;
    private PostFile $file;
    private User $author;
    private User $reader;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->author = $this->user(11);
        $this->reader = $this->user(22);
        $this->post = (new Post())->setAuthor($this->author)->setIsActive(false)->setCoverImageName('cover.png');
        (new \ReflectionProperty(Post::class, 'id'))->setValue($this->post, 7);
        $this->file = (new PostFile())->setPost($this->post)->setFilePath('7/cover.png');
        (new \ReflectionProperty(PostFile::class, 'id'))->setValue($this->file, 9);

        $this->directory = $container->getParameter('kernel.cache_dir') . '/post-images-test-' . bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->directory . '/uploads/7');
        $container->get(ImagineInterface::class)->create(new Box(800, 600))->save($this->directory . '/uploads/7/cover.png');
        $posts = $this->createMock(PostRepository::class);
        $posts->method('find')->willReturnCallback(fn ($id) => (int) $id === 7 ? $this->post : null);
        $files = $this->createMock(FileRepository::class);
        $files->method('find')->willReturnCallback(fn ($id) => (int) $id === 9 ? $this->file : null);
        $container->set(PostImageController::class, new PostImageController(
            $posts,
            $files,
            $container->get(UriSigner::class),
            $container->get('liip_imagine.filter.manager'),
            $filesystem,
            $this->directory . '/uploads',
            $this->directory . '/thumbnails',
        ));
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            (new Filesystem())->remove($this->directory);
        }
        parent::tearDown();
    }

    public function testFormattedImagesWorkWithoutJwtAndThumbnailsStayPrivate(): void
    {
        $this->authenticate($this->author, ['ROLE_USER']);
        $formatter = self::getContainer()->get(PostResponseFormatter::class);
        $data = $formatter->formatPostListItem($this->post);
        $fileData = $formatter->formatFile($this->file);
        self::getContainer()->get('security.token_storage')->setToken(null);

        foreach ([
            [$data['coverImageUrl'], [800, 600]],
            [$data['coverThumbnailUrl'], [400, 400]],
            [$fileData['previewUrl'], [400, 400]],
        ] as [$url, $dimensions]) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertFalse($this->client->getRequest()->headers->has('Authorization'));
            $response = $this->client->getResponse();
            self::assertInstanceOf(BinaryFileResponse::class, $response);
            self::assertSame($dimensions, array_slice(getimagesize($response->getFile()->getPathname()), 0, 2));
            self::assertResponseHeaderSame('Content-Type', 'image/png');
            self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
            self::assertTrue($response->headers->hasCacheControlDirective('private'));
            self::assertFalse($response->headers->hasCacheControlDirective('public'));
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            self::assertLessThanOrEqual(3600, (int) $query['_expiration'] - time());
            self::assertGreaterThan(0, $response->getMaxAge());
            self::assertLessThanOrEqual((int) $query['_expiration'] - $response->getDate()->getTimestamp(), $response->getMaxAge());
            if ($dimensions === [400, 400]) {
                self::assertStringStartsWith($this->directory . '/thumbnails/', $response->getFile()->getPathname());
            }
        }

        // Cover and attachment refer to the same source; repeat requests reuse one private thumbnail.
        $cached = glob($this->directory . '/thumbnails/*');
        self::assertCount(1, $cached);
        touch($cached[0], time() - 120);
        clearstatcache(true, $cached[0]);
        $mtime = filemtime($cached[0]);
        $this->client->request('GET', $data['coverThumbnailUrl']);
        self::assertResponseIsSuccessful();
        clearstatcache(true, $cached[0]);
        self::assertSame($mtime, filemtime($cached[0]));
    }

    public function testInvalidExpiredAndMissingSignaturesAreForbiddenWithoutJwt(): void
    {
        $this->authenticate($this->author, ['ROLE_USER']);
        $generator = self::getContainer()->get(PostImagePreviewUrlGenerator::class);
        $urls = [$generator->getCoverImageUrl($this->post), $generator->getCoverPreviewUrl($this->post), $generator->getFilePreviewUrl($this->file)];
        $signer = self::getContainer()->get(UriSigner::class);
        self::getContainer()->get('security.token_storage')->setToken(null);

        foreach ($urls as $url) {
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            unset($query['_hash'], $query['_expiration']);
            $unsigned = explode('?', $url)[0] . ($query ? '?' . http_build_query($query) : '');
            foreach ([
                preg_replace('/_hash=[^&]+/', '_hash=invalid', $url),
                $signer->sign($unsigned, time() - 1),
                $signer->sign($unsigned),
                $unsigned,
                $url . '&thumbnail=changed',
            ] as $invalidUrl) {
                $this->client->request('GET', $invalidUrl);
                self::assertResponseStatusCodeSame(403);
            }
        }
    }

    public function testCacheLifetimeDoesNotExtendAShortSignature(): void
    {
        $container = self::getContainer();
        $url = $container->get('router')->generate('spa_api_post_images_cover', ['id' => 7], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->client->request('GET', $container->get(UriSigner::class)->sign($url, time() + 30));

        self::assertResponseIsSuccessful();
        self::assertLessThanOrEqual(30, $this->client->getResponse()->getMaxAge());
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('must-revalidate'));
    }

    public function testOnlyViewersAllowedByPostVoterReceiveSignedUrls(): void
    {
        $generator = self::getContainer()->get(PostImagePreviewUrlGenerator::class);
        foreach ([
            [$this->reader, ['ROLE_USER'], false, false, false],
            [$this->reader, ['ROLE_MANAGER'], false, false, false],
            [$this->author, ['ROLE_USER'], false, false, true],
            [$this->reader, ['ROLE_ADMIN'], false, false, true],
            [$this->reader, ['ROLE_USER'], true, false, true],
            [$this->author, ['ROLE_ADMIN'], true, true, false],
            [null, [], true, false, false],
        ] as [$user, $roles, $active, $deleted, $allowed]) {
            $this->post->setIsActive($active)->setDeletedAt($deleted ? new \DateTimeImmutable() : null);
            $this->authenticate($user, $roles);
            foreach ([
                fn () => $generator->getCoverImageUrl($this->post),
                fn () => $generator->getCoverPreviewUrl($this->post),
                fn () => $generator->getFilePreviewUrl($this->file),
                fn () => self::getContainer()->get(PostResponseFormatter::class)->formatPostDetail($this->post),
            ] as $generate) {
                try {
                    self::assertNotEmpty($generate());
                    self::assertTrue($allowed, 'A forbidden viewer received an image URL.');
                } catch (AccessDeniedException) {
                    self::assertFalse($allowed, 'An allowed viewer was denied an image URL.');
                }
            }
        }
    }

    private function authenticate(?User $user, array $roles): void
    {
        self::getContainer()->get('security.token_storage')->setToken(
            $user === null ? null : new UsernamePasswordToken($user, 'main', $roles),
        );
    }

    private function user(int $id): User
    {
        $user = (new User())->setLogin('post-image-test-' . $id);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
