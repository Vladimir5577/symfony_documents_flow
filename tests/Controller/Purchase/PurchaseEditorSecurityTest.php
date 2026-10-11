<?php

declare(strict_types=1);

namespace App\Tests\Controller\Purchase;

use App\Controller\SpaApi\Purchase\PurchaseFileEditorController;
use App\Entity\Purchase\PurchaseApprovalStage;
use App\Entity\Purchase\PurchaseApprovalTask;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\Purchase\PurchaseRequestHistory;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseContractReview;
use App\Enum\Purchase\PurchaseHistoryAction;
use App\Enum\Purchase\PurchaseStageStatus;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseTaskAssignment;
use App\Enum\Purchase\PurchaseTaskDecision;
use App\Repository\Purchase\PurchaseApproverRoleRepository;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Repository\User\UserRepository;
use App\Service\Notification\NotificationPublisher;
use App\Service\OnlyOffice\OnlyOfficeJwt;
use App\Service\Purchase\PurchaseAccess;
use App\Service\Purchase\PurchaseFileCoEditing;
use App\Service\Purchase\PurchaseFileStorageService;
use App\Service\Purchase\PurchaseHistoryLogger;
use App\Service\Purchase\PurchaseNotificationPublisher;
use App\Service\Purchase\PurchaseRoster;
use Aws\Result;
use Aws\S3\S3Client;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Log\NullLogger;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

final class PurchaseEditorSecurityTest extends KernelTestCase
{
    private PurchaseFileEditorController $controller;
    private PurchaseRequest $purchase;
    private PurchaseRequestFile $file;
    private PurchaseApprovalTask $task;
    private PurchaseApprovalStage $stage;
    private User $editor;
    private User $other;
    private ArrayAdapter $cache;
    private OnlyOfficeJwt $jwt;
    private int $reads = 0;
    private int $writes = 0;
    private bool $allowWrite = false;
    private EntityManagerInterface&MockObject $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->editor = (new User())->setLogin('editor');
        $this->other = (new User())->setLogin('other');
        self::setId($this->editor, 1);
        self::setId($this->other, 2);
        $this->purchase = (new PurchaseRequest())->setStatus(PurchaseStatus::ON_APPROVAL);
        self::setId($this->purchase, 10);
        $this->stage = (new PurchaseApprovalStage())->setStatus(PurchaseStageStatus::ACTIVE);
        self::setId($this->stage, 20);
        $this->task = (new PurchaseApprovalTask())->setAssignmentType(PurchaseTaskAssignment::USER)->setAssigneeUser($this->editor);
        self::setId($this->task, 30);
        $this->stage->addTask($this->task);
        $this->purchase->addStage($this->stage);
        $this->file = (new PurchaseRequestFile())->setStorageKey('10/contract.docx')->setType(PurchaseFileType::CONTRACT);
        self::setId($this->file, 40);
        $this->purchase->addFile($this->file);

        $purchases = $this->createMock(PurchaseRequestRepository::class);
        $purchases->method('find')->with(10)->willReturn($this->purchase);
        $roles = $this->createMock(PurchaseApproverRoleRepository::class);
        $roles->method('findRoleCodesForUser')->willReturn([]);
        $roster = new PurchaseRoster($roles);
        $access = new PurchaseAccess($roster);
        $em = $this->em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(fn (string $class, int $id) => $id === 1 ? $this->editor : $this->other);
        $em->method('flush')->willReturnCallback(function (): void {
            self::assertTrue($this->allowWrite, 'Unexpected database write');
        });
        $s3 = new S3Client([
            'region' => 'test', 'version' => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => function ($command): PromiseInterface {
                if ($command->getName() === 'PutObject') {
                    self::assertTrue($this->allowWrite, 'Unexpected storage write');
                    ++$this->writes;

                    return Create::promiseFor(new Result());
                }
                self::assertSame('GetObject', $command->getName());
                ++$this->reads;

                return Create::promiseFor(new Result(['Body' => Utils::streamFor('current bytes'), 'ContentLength' => 13]));
            },
        ]);
        $storage = new PurchaseFileStorageService($s3, 'test');
        $notifier = new PurchaseNotificationPublisher(
            new NotificationPublisher($this->createMock(MessageBusInterface::class)),
            $roster,
            $this->createMock(UserRepository::class),
        );
        $coEditing = new PurchaseFileCoEditing($storage, new PurchaseHistoryLogger($em), $notifier, $access, $em, new NullLogger(), 'test');
        $this->cache = new ArrayAdapter();
        $this->jwt = new OnlyOfficeJwt('document-server-secret');
        $this->controller = new PurchaseFileEditorController($purchases, $access, $storage, $coEditing, $this->jwt, $em, $this->cache, 'test', 'https://office.example');
        $this->controller->setContainer(self::getContainer());
    }

    public function testCurrentContentWorksAndOldRevisionCannotReadReplacement(): void
    {
        $opened = $this->open();
        self::assertSame(200, $this->controller->content(10, 40, $this->contentRequest($opened))->getStatusCode());
        self::assertSame(1, $this->reads);
        $this->file->nextEditorRevision();
        self::assertSame(404, $this->controller->content(10, 40, $this->contentRequest($opened))->getStatusCode());
        self::assertSame(1, $this->reads);
        self::assertSame(403, $this->controller->save(10, 40, $this->saveRequest($opened), $this->editor)->getStatusCode());
    }

    public function testRevokedAccessInvalidatesContentImmediately(): void
    {
        $opened = $this->open();
        $this->task->setAssigneeUser($this->other);
        self::assertSame(403, $this->controller->open(10, 40, $this->editor)->getStatusCode());
        self::assertSame(404, $this->controller->content(10, 40, $this->contentRequest($opened))->getStatusCode());
        self::assertSame(0, $this->reads);
    }

    public function testExpiredSessionCannotReadOrSave(): void
    {
        $opened = $this->open();
        $item = $this->cache->getItem('purchase_editor_session_' . $opened['token']);
        $session = $item->get();
        $session['expiresAt'] = time() - 1;
        $item->set($session);
        $this->cache->save($item);
        self::assertSame(404, $this->controller->content(10, 40, $this->contentRequest($opened))->getStatusCode());
        self::assertSame(403, $this->controller->save(10, 40, $this->saveRequest($opened), $this->editor)->getStatusCode());
        self::assertSame(0, $this->reads);
    }

    public function testHeartbeatExtendsOwnActiveSessionWithoutChangingDocumentKey(): void
    {
        $opened = $this->open();
        $item = $this->cache->getItem('purchase_editor_session_' . $opened['token']);
        $session = $item->get();
        $session['expiresAt'] = time() + 10;
        $item->set($session);
        $this->cache->save($item);

        $response = $this->controller->heartbeat(10, 40, $this->saveRequest($opened), $this->editor);
        self::assertSame(200, $response->getStatusCode());
        $renewed = $this->cache->getItem('purchase_editor_session_' . $opened['token'])->get();
        self::assertGreaterThan(time() + 7100, $renewed['expiresAt']);
        unset($session['expiresAt'], $renewed['expiresAt']);
        self::assertSame($session, $renewed);
        self::assertSame(200, $this->controller->content(10, 40, $this->contentRequest($opened))->getStatusCode());
    }

    public function testHeartbeatCannotExtendAnotherUsersOrExpiredSession(): void
    {
        $opened = $this->open();
        $cacheKey = 'purchase_editor_session_' . $opened['token'];
        $session = $this->cache->getItem($cacheKey)->get();
        self::assertSame(403, $this->controller->heartbeat(10, 40, $this->saveRequest($opened), $this->other)->getStatusCode());
        self::assertSame(403, $this->controller->heartbeat(10, 40, $this->saveRequest($opened), null)->getStatusCode());
        self::assertSame($session, $this->cache->getItem($cacheKey)->get());
        $item = $this->cache->getItem($cacheKey);
        $session['expiresAt'] = time() - 1;
        $item->set($session);
        $this->cache->save($item);
        self::assertSame(403, $this->controller->heartbeat(10, 40, $this->saveRequest($opened), $this->editor)->getStatusCode());
        self::assertFalse($this->cache->hasItem($cacheKey));
    }

    public function testHeartbeatRechecksTaskAccessAndDocumentRevision(): void
    {
        $opened = $this->open();
        $this->task->setDecision(PurchaseTaskDecision::APPROVED);
        self::assertSame(403, $this->controller->heartbeat(10, 40, $this->saveRequest($opened), $this->editor)->getStatusCode());
        $this->task->setDecision(PurchaseTaskDecision::PENDING);
        $this->task->setAssigneeUser($this->other);
        self::assertSame(403, $this->controller->heartbeat(10, 40, $this->saveRequest($opened), $this->editor)->getStatusCode());
        $opened = $this->open($this->other);
        $this->file->nextEditorRevision();
        self::assertSame(403, $this->controller->heartbeat(10, 40, $this->saveRequest($opened), $this->other)->getStatusCode());
    }

    public function testCompletedTaskCannotSaveWithPreviouslyIssuedEditToken(): void
    {
        $opened = $this->open();
        $this->task->setDecision(PurchaseTaskDecision::APPROVED);
        self::assertSame('view', $this->open()['mode']);
        self::assertSame(403, $this->controller->save(10, 40, $this->saveRequest($opened), $this->editor)->getStatusCode());
        self::assertSame(0, $this->reads);
    }

    public function testReturnToSameStageDoesNotReviveOldToken(): void
    {
        $opened = $this->open();
        $returned = (new PurchaseRequestHistory())->setAction(PurchaseHistoryAction::RETURNED_TO_DEPARTMENT);
        self::setId($returned, 100);
        $this->purchase->addHistory($returned);
        self::assertNotSame($opened['config']['document']['key'], $this->open()['config']['document']['key']);
        self::assertSame(403, $this->controller->save(10, 40, $this->saveRequest($opened), $this->editor)->getStatusCode());
        self::assertSame(404, $this->controller->content(10, 40, $this->contentRequest($opened))->getStatusCode());
    }

    public function testNextStageGetsSeparateDocumentSession(): void
    {
        $opened = $this->open();
        $this->stage->setStatus(PurchaseStageStatus::COMPLETED);
        $next = (new PurchaseApprovalStage())->setPosition(2)->setStatus(PurchaseStageStatus::ACTIVE);
        self::setId($next, 21);
        $nextTask = (new PurchaseApprovalTask())->setAssignmentType(PurchaseTaskAssignment::USER)->setAssigneeUser($this->other);
        self::setId($nextTask, 31);
        $next->addTask($nextTask);
        $this->purchase->addStage($next);
        self::assertNotSame($opened['config']['document']['key'], $this->open($this->other)['config']['document']['key']);
        self::assertSame(404, $this->controller->content(10, 40, $this->contentRequest($opened))->getStatusCode());
        self::assertSame(403, $this->controller->save(10, 40, $this->saveRequest($opened), $this->editor)->getStatusCode());
    }

    public function testUnrelatedPurchaseUpdateDoesNotInvalidateCoediting(): void
    {
        $opened = $this->open();
        (new \ReflectionProperty(PurchaseRequest::class, 'version'))->setValue($this->purchase, $this->purchase->getVersion() + 1);
        $this->task->passContractReview(40);
        self::assertSame(200, $this->controller->content(10, 40, $this->contentRequest($opened))->getStatusCode());
    }

    public function testCoeditorsShareDocumentKeyButCannotUseEachOthersSaveToken(): void
    {
        $otherTask = (new PurchaseApprovalTask())->setAssignmentType(PurchaseTaskAssignment::USER)->setAssigneeUser($this->other);
        self::setId($otherTask, 31);
        $this->stage->addTask($otherTask);
        $first = $this->open();
        $second = $this->open($this->other);
        self::assertSame($first['config']['document']['key'], $second['config']['document']['key']);
        self::assertNotSame($first['token'], $second['token']);
        self::assertSame(403, $this->controller->save(10, 40, $this->saveRequest($first), $this->other)->getStatusCode());
        self::assertSame(200, $this->controller->content(10, 40, $this->contentRequest($second))->getStatusCode());
    }

    public function testCallbackWithoutServerSavePermissionCannotCommit(): void
    {
        $opened = $this->open();
        self::assertSame(['error' => 0], $this->callback($opened));
        self::assertSame(0, $this->reads);
    }

    public function testCallbackRechecksCommitterEvenWhenUsingAnotherCoeditorsUrl(): void
    {
        $otherTask = (new PurchaseApprovalTask())->setAssignmentType(PurchaseTaskAssignment::USER)->setAssigneeUser($this->other);
        self::setId($otherTask, 31);
        $this->stage->addTask($otherTask);
        $committer = $this->open();
        $receiver = $this->open($this->other);
        $item = $this->cache->getItem('purchase_editor_save_40_0123456789abcdef');
        $item->set(['session' => $committer['token'], 'accept' => false]);
        $this->cache->save($item);
        // URL соавтора допустим, даже когда он завершил свою задачу; право записи — у committer.
        $otherTask->setDecision(PurchaseTaskDecision::APPROVED);
        self::assertSame(['error' => 1], $this->callback($receiver));
        $this->task->setDecision(PurchaseTaskDecision::APPROVED);
        $this->em->expects(self::once())->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback) => $callback());
        $this->commitDownloaded($receiver);
        self::assertSame(0, $this->reads);
    }

    public function testRevokedCallbackOwnerDoesNotBlockAuthorizedCoauthorSave(): void
    {
        $otherTask = (new PurchaseApprovalTask())->setAssignmentType(PurchaseTaskAssignment::USER)->setAssigneeUser($this->other);
        self::setId($otherTask, 31);
        $this->stage->addTask($otherTask);
        $committer = $this->open();
        $receiver = $this->open($this->other);
        $item = $this->cache->getItem('purchase_editor_save_40_0123456789abcdef');
        $item->set(['session' => $committer['token'], 'accept' => false]);
        $this->cache->save($item);
        $otherTask->setAssigneeUser($this->editor);
        // Чтение A отзывает и удаляет его серверную сессию. Callback URL всё ещё выбирает DS.
        self::assertSame(404, $this->controller->content(10, 40, $this->contentRequest($receiver))->getStatusCode());
        self::assertFalse($this->cache->hasItem('purchase_editor_session_' . $receiver['token']));
        self::assertSame(['error' => 1], $this->callback($receiver));

        $this->allowWrite = true;
        $this->file->setType(PurchaseFileType::OTHER);
        $this->em->expects(self::once())->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback) => $callback());
        $path = tempnam(sys_get_temp_dir(), 'editor-test-');
        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
            $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
            $zip->close();
            $this->commitDownloaded($receiver, file_get_contents($path));
        } finally {
            unlink($path);
        }
        self::assertSame(1, $this->writes);
        self::assertTrue($this->cache->hasItem('purchase_file_editor_40_0123456789abcdef'));
        self::assertSame($this->editor, $this->purchase->getHistory()->last()->getUser());
    }

    public function testTransportSignatureAndCurrentKeyAreRequiredWithoutOwnersSession(): void
    {
        $opened = $this->open();
        $item = $this->cache->getItem('purchase_editor_save_40_0123456789abcdef');
        $item->set(['session' => $opened['token'], 'accept' => false]);
        $this->cache->save($item);
        $tampered = $opened;
        $tampered['config']['editorConfig']['callbackUrl'] .= '&token=invalid';
        self::assertSame(['error' => 0], $this->callback($tampered));
        self::assertSame(['error' => 0], $this->callback($opened, false));
        $this->file->nextEditorRevision();
        self::assertSame(['error' => 0], $this->callback($opened));
        self::assertSame(0, $this->writes);
    }

    public function testTaskCompletedDuringDownloadIsRejectedAfterLockedRefresh(): void
    {
        $opened = $this->open();
        $item = $this->cache->getItem('purchase_editor_save_40_0123456789abcdef');
        $item->set(['session' => $opened['token'], 'accept' => false]);
        $this->cache->save($item);
        $inTransaction = false;
        $locked = false;
        $this->em->expects(self::once())->method('wrapInTransaction')->willReturnCallback(static function (callable $callback) use (&$inTransaction) {
            $inTransaction = true;
            try {
                return $callback();
            } finally {
                $inTransaction = false;
            }
        });
        $this->em->method('refresh')->willReturnCallback(function (object $entity, mixed $mode = null) use (&$inTransaction, &$locked): void {
            self::assertTrue($inTransaction);
            if ($entity === $this->purchase) {
                self::assertSame(LockMode::PESSIMISTIC_WRITE, $mode);
                $locked = true;
            } else {
                self::assertTrue($locked);
            }
            if ($entity === $this->task) {
                $this->task->setDecision(PurchaseTaskDecision::APPROVED);
            }
        });
        $this->commitDownloaded($opened);
        self::assertTrue($locked);
        self::assertSame(0, $this->reads);
        self::assertFalse($this->cache->hasItem('purchase_file_editor_40_0123456789abcdef'));
    }

    public function testRevisionChangedDuringDownloadIsRejectedAfterLockedRefresh(): void
    {
        $opened = $this->open();
        $item = $this->cache->getItem('purchase_editor_save_40_0123456789abcdef');
        $item->set(['session' => $opened['token'], 'accept' => false]);
        $this->cache->save($item);
        $this->em->expects(self::once())->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback) => $callback());
        $this->em->method('refresh')->willReturnCallback(function (object $entity, mixed $mode = null): void {
            if ($entity === $this->file) {
                $this->file->nextEditorRevision();
            }
        });
        $this->commitDownloaded($opened);
        self::assertSame(0, $this->reads);
        self::assertFalse($this->cache->hasItem('purchase_file_editor_40_0123456789abcdef'));
    }

    public function testNoChangesAcceptanceRechecksTaskAfterCommand(): void
    {
        $this->task->setContractReview(PurchaseContractReview::ACCEPT);
        $opened = $this->open();
        $inTransaction = false;
        $locked = false;
        $this->em->expects(self::once())->method('wrapInTransaction')->willReturnCallback(static function (callable $callback) use (&$inTransaction) {
            $inTransaction = true;
            try {
                return $callback();
            } finally {
                $inTransaction = false;
            }
        });
        $this->em->method('refresh')->willReturnCallback(function (object $entity, mixed $mode = null) use (&$inTransaction, &$locked): void {
            self::assertTrue($inTransaction);
            if ($entity === $this->purchase) {
                self::assertSame(LockMode::PESSIMISTIC_WRITE, $mode);
                $locked = true;
            } else {
                self::assertTrue($locked);
            }
            if ($entity === $this->task) {
                $this->task->setDecision(PurchaseTaskDecision::APPROVED);
            }
        });
        $result = (new \ReflectionMethod($this->controller, 'acceptStoredForSession'))->invoke(
            $this->controller, $this->purchase, $this->file, $this->editor, $opened['token'],
        );
        self::assertNull($result);
        self::assertTrue($locked);
        self::assertSame(0, $this->reads);
    }

    private function commitDownloaded(array $opened, string $content = 'downloaded bytes must not be committed'): void
    {
        $data = ['status' => 6, 'key' => $opened['config']['document']['key'], 'userdata' => 'commit.0123456789abcdef.1', 'users' => ['2'], 'forcesavetype' => 0];
        (new \ReflectionMethod($this->controller, 'commitDownloaded'))->invoke(
            $this->controller,
            $this->purchase,
            $this->file,
            $data,
            $content,
            Request::create($opened['config']['editorConfig']['callbackUrl']),
        );
    }

    private function callback(array $opened, bool $signed = true): array
    {
        $data = ['status' => 6, 'key' => $opened['config']['document']['key'], 'userdata' => 'commit.0123456789abcdef.1', 'url' => 'file:///denied'];
        if ($signed) {
            $data['token'] = $this->jwt->encode($data);
        }
        $request = Request::create($opened['config']['editorConfig']['callbackUrl'], 'POST', content: json_encode($data, JSON_THROW_ON_ERROR));

        return json_decode($this->controller->callback(10, 40, $request)->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function open(?User $user = null): array
    {
        $response = $this->controller->open(10, 40, $user ?? $this->editor);
        self::assertSame(200, $response->getStatusCode());

        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function contentRequest(array $opened): Request
    {
        return Request::create($opened['config']['document']['url']);
    }

    private function saveRequest(array $opened): Request
    {
        return Request::create('/', 'POST', content: json_encode([
            'token' => $opened['token'], 'key' => $opened['config']['document']['key'], 'mode' => $opened['mode'],
        ], JSON_THROW_ON_ERROR));
    }

    private static function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
