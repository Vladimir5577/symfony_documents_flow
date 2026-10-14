<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\Chat\ChatController;
use App\Controller\Post\PostController as LegacyPostController;
use App\Controller\SpaApi\Post\PostController;
use App\Entity\Chat\ChatParticipant;
use App\Entity\Chat\ChatRoom;
use App\Entity\Post\File as PostFile;
use App\Entity\Post\Post;
use App\Entity\User\User;
use App\Repository\Chat\ChatMessageReadRepository;
use App\Repository\Chat\ChatMessageRepository;
use App\Repository\Chat\ChatParticipantRepository;
use App\Repository\Post\FileRepository;
use App\Repository\Post\PostRepository;
use App\Repository\Post\PostUserCommentRepository;
use App\Repository\Post\PostUserStatusRepository;
use App\Security\Voter\PostVoter;
use App\Service\Chat\ChatMessageService;
use App\Service\SpaApi\Post\PostImagePreviewUrlGenerator;
use App\Service\SpaApi\Post\PostResponseFormatter;
use App\Service\User\UserAvatarUrlGenerator;
use App\Service\ImgproxyUrlSigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\HubRegistry;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class PostChatAccessTest extends KernelTestCase
{
    public function testDraftVisibilityUsesConfiguredVoterAndRoleHierarchy(): void
    {
        self::bootKernel();
        $author = $this->user(11);
        $reader = $this->user(22);
        $post = (new Post())->setAuthor($author)->setIsActive(false);
        $checker = self::getContainer()->get('security.authorization_checker');
        $storage = self::getContainer()->get('security.token_storage');

        foreach ([
            [$reader, ['ROLE_USER'], false],
            [$reader, ['ROLE_MANAGER'], false],
            [$author, ['ROLE_USER'], true],
            [$reader, ['ROLE_ADMIN'], true],
        ] as [$user, $roles, $allowed]) {
            $storage->setToken(new UsernamePasswordToken($user, 'main', $roles));
            self::assertSame($allowed, $checker->isGranted(PostVoter::VIEW, $post));
        }

        $post->setDeletedAt(new \DateTimeImmutable());
        self::assertFalse($checker->isGranted(PostVoter::VIEW, $post));
        $post->setDeletedAt(null)->setIsActive(true);
        $storage->setToken(new UsernamePasswordToken($reader, 'main', ['ROLE_USER']));
        self::assertTrue($checker->isGranted(PostVoter::VIEW, $post));
    }

    public function testSpaDraftEndpointsDenyBeforeRelatedReadsAndWrites(): void
    {
        self::bootKernel();
        $reader = $this->user(22);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($reader, 'main', ['ROLE_USER']));
        $post = (new Post())->setAuthor($this->user(11))->setIsActive(false);
        $posts = $this->createMock(PostRepository::class);
        $posts->method('find')->willReturn($post);
        $comments = $this->createMock(PostUserCommentRepository::class);
        $comments->expects(self::never())->method('findByPostPaginated');
        $comments->expects(self::never())->method('count');
        $statuses = $this->createMock(PostUserStatusRepository::class);
        $statuses->expects(self::never())->method('findOneBy');
        $files = $this->createMock(FileRepository::class);
        $files->method('find')->willReturn((new PostFile())->setPost($post));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $formatter = new PostResponseFormatter($urls, new PostImagePreviewUrlGenerator(
            $urls,
            self::getContainer()->get(UriSigner::class),
            self::getContainer()->get('security.authorization_checker'),
        ));
        $controller = new PostController($posts, $comments, $statuses, $files, $formatter, $em, '/unused');
        $controller->setContainer(self::getContainer());

        foreach ([
            fn () => $controller->view(1, $reader),
            fn () => $controller->listComments(1, new Request()),
            fn () => $controller->createComment(1, Request::create('/', 'POST', content: '{"content":"test"}'), $reader),
            fn () => $controller->acknowledge(1, $reader),
            fn () => $controller->downloadFile(1, new Request()),
            fn () => $controller->cover(1),
        ] as $request) {
            try {
                $request();
                self::fail('Draft endpoint allowed an unrelated employee.');
            } catch (AccessDeniedException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testMercureEventsArePrivateAndRemovedParticipantStopsReceiving(): void
    {
        $room = new ChatRoom();
        (new \ReflectionProperty(ChatRoom::class, 'id'))->setValue($room, 7);
        $first = (new ChatParticipant())->setRoom($room)->setUser($this->user(11));
        $second = (new ChatParticipant())->setRoom($room)->setUser($this->user(22));
        $participants = $this->createMock(ChatParticipantRepository::class);
        $participants->method('findByRoom')->willReturnOnConsecutiveCalls([$first, $second], [$first]);
        $updates = [];
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$updates): string {
            $updates[] = $update;
            return 'event-id';
        });
        $service = new ChatMessageService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(ChatMessageRepository::class),
            $this->createMock(ChatMessageReadRepository::class),
            $participants,
            $hub,
            new UserAvatarUrlGenerator('', '', new ImgproxyUrlSigner('', '')),
        );

        $service->publishToMercure($room, ['type' => 'new_message', 'message' => ['content' => 'private marker']]);
        self::assertCount(4, $updates);
        foreach ($updates as $update) {
            self::assertTrue($update->isPrivate());
            self::assertContains($update->getTopics(), [['/chat/user/11'], ['/chat/user/22']]);
        }
        self::assertStringContainsString('private marker', $updates[0]->getData());

        $updates = [];
        $service->publishToMercure($room, ['type' => 'message_edited', 'message' => ['content' => 'after removal']]);
        self::assertCount(2, $updates);
        foreach ($updates as $update) {
            self::assertTrue($update->isPrivate());
            self::assertSame(['/chat/user/11'], $update->getTopics());
        }
    }

    public function testLegacyDraftGuardsRunBeforeTheController(): void
    {
        $kernel = self::bootKernel();
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($this->user(22), 'main', ['ROLE_USER']));
        $post = (new Post())->setAuthor($this->user(11))->setIsActive(false);
        $controller = new LegacyPostController();
        $dispatcher = self::getContainer()->get('event_dispatcher');

        foreach (['addComment', 'loadComments', 'acknowledgePost', 'cover'] as $method) {
            $event = new ControllerArgumentsEvent($kernel, [$controller, $method], [$post], new Request(), HttpKernelInterface::MAIN_REQUEST);
            try {
                $dispatcher->dispatch($event, KernelEvents::CONTROLLER_ARGUMENTS);
                self::fail('Legacy draft endpoint did not enforce its visibility guard.');
            } catch (AccessDeniedException) {
                self::addToAssertionCount(1);
            }
        }

        $files = $this->createMock(FileRepository::class);
        $files->method('find')->willReturn((new PostFile())->setPost($post));
        $controller->setContainer(self::getContainer());
        $this->expectException(AccessDeniedException::class);
        $controller->downloadFile(1, $files);
    }

    public function testSubscriberJwtContainsOnlyAuthenticatedUsersTopic(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->method('getPublicUrl')->willReturn('https://portal.test/.well-known/mercure');
        $hub->method('getFactory')->willReturn(new LcobucciFactory(str_repeat('test-secret-', 4)));
        $authorization = new Authorization(new HubRegistry($hub));
        $request = Request::create('https://portal.test/api/chat/subscription?user_id=22&topic=*');
        $response = (new ChatController())->subscription($request, $authorization, $this->user(11));
        self::assertSame(['topic' => '/chat/user/11'], json_decode($response->getContent(), true));
        $cookie = $response->headers->getCookies()[0];
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        [, $payload] = explode('.', $cookie->getValue());
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        self::assertSame(['/chat/user/11'], $claims['mercure']['subscribe']);
        self::assertSame([], $claims['mercure']['publish']);
        self::assertGreaterThan(time(), $claims['exp']);
        self::assertLessThanOrEqual(time() + 3600, $claims['exp']);
    }

    private function user(int $id): User
    {
        $user = (new User())->setLogin('post-chat-test-' . $id);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
