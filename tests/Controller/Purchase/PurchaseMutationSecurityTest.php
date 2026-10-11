<?php

declare(strict_types=1);

namespace App\Tests\Controller\Purchase;

use App\Controller\SpaApi\Purchase\PurchaseFileController;
use App\Controller\SpaApi\Purchase\PurchaseTransitionController;
use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseApprovalStage;
use App\Entity\Purchase\PurchaseApprovalTask;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\Purchase\PurchaseRequestItem;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Enum\Purchase\PurchaseStageStatus;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseTaskDecision;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Service\Purchase\PurchaseApprovalWorkflow;
use App\Service\Purchase\PurchaseFileStorageService;
use App\Service\Purchase\PurchaseTransitionException;
use Aws\Result;
use Aws\S3\S3Client;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class PurchaseMutationSecurityTest extends KernelTestCase
{
    private EntityManagerInterface&MockObject $em;
    private S3Client&MockObject $s3;
    private PurchaseRequest $purchase;
    private User $user;
    private bool $inTransaction = false;
    private bool $committed = false;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->user = (new User())->setLogin('fixture');
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->user, 10);
        $this->purchase = (new PurchaseRequest())->setStatus(PurchaseStatus::APPROVED)->setCreatedBy($this->user);
        (new \ReflectionProperty(PurchaseRequest::class, 'id'))->setValue($this->purchase, 1);
        $repository = $this->createMock(PurchaseRequestRepository::class);
        $repository->method('find')->willReturn($this->purchase);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->expects(self::once())->method('wrapInTransaction')->willReturnCallback(function (callable $callback) {
            $this->inTransaction = true;
            try {
                $result = $callback();
                $this->committed = true;

                return $result;
            } finally {
                $this->inTransaction = false;
            }
        });
        $this->s3 = $this->createMock(S3Client::class);
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn(false);
        $container->set('security.authorization_checker', $checker);
        $container->set(PurchaseRequestRepository::class, $repository);
        $container->set('doctrine.orm.entity_manager', $this->em);
        $container->set(PurchaseFileStorageService::class, new PurchaseFileStorageService($this->s3, 'test'));
    }

    public static function paidStates(): iterable
    {
        yield 'paid' => [PurchaseStatus::INVOICE_PAID, PurchaseStageStatus::COMPLETED];
        yield 'partially confirmed payment' => [PurchaseStatus::APPROVED, PurchaseStageStatus::ACTIVE];
    }

    #[DataProvider('paidStates')]
    public function testReturnCannotErasePayment(PurchaseStatus $status, PurchaseStageStatus $paymentStatus): void
    {
        $this->purchase->setStatus($status);
        $sourcing = $this->stage(PurchaseStagePurpose::SOURCING, 1, PurchaseStageStatus::COMPLETED);
        $sourcingTask = (new PurchaseApprovalTask())->setDecision(PurchaseTaskDecision::APPROVED);
        $sourcing->addTask($sourcingTask);
        $payment = $this->stage(PurchaseStagePurpose::PAYMENT, 2, $paymentStatus);
        $paidTask = (new PurchaseApprovalTask())->decide(PurchaseTaskDecision::APPROVED, $this->user, 'Paid');
        $payment->addTask($paidTask);
        $active = $paymentStatus === PurchaseStageStatus::ACTIVE
            ? $payment
            : $this->stage(PurchaseStagePurpose::DELIVERY, 3, PurchaseStageStatus::ACTIVE);
        $task = (new PurchaseApprovalTask())->setAssigneeUser($this->user);
        (new \ReflectionProperty(PurchaseApprovalTask::class, 'id'))->setValue($task, 3);
        $active->addTask($task);
        $this->em->method('refresh')->willReturnCallback(function (object $entity, mixed $mode = null): void {
            self::assertTrue($this->inTransaction);
            if ($entity === $this->purchase) {
                self::assertSame(LockMode::PESSIMISTIC_WRITE, $mode);
            }
        });
        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');

        $response = self::getContainer()->get(PurchaseTransitionController::class)->returnTask(
            1, 3, Request::create('/', 'POST', content: '{"comment":"Correct documents"}'), $this->user,
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['error' => SpaApiError::PURCHASE_INVALID_STATUS], json_decode($response->getContent(), true));
        self::assertSame($status, $this->purchase->getStatus());
        self::assertSame(PurchaseTaskDecision::APPROVED, $paidTask->getDecision());
        self::assertSame('Paid', $paidTask->getComment());
        self::assertSame(PurchaseTaskDecision::APPROVED, $sourcingTask->getDecision());
        self::assertFalse($this->committed);
    }

    public function testDeleteRereadsPaymentBeforeRemovingFileOrBindings(): void
    {
        $file = $this->file(PurchaseFileType::INVOICE);
        $item = (new PurchaseRequestItem())->setInvoiceFile($file);
        $this->purchase->addItem($item);
        $this->em->expects(self::exactly(2))->method('refresh')->willReturnCallback(function (object $entity, mixed $mode = null): void {
            self::assertTrue($this->inTransaction);
            if ($entity === $this->purchase) {
                self::assertSame(LockMode::PESSIMISTIC_WRITE, $mode);
                $this->purchase->setStatus(PurchaseStatus::INVOICE_PAID);
            }
        });
        $this->em->expects(self::never())->method('remove');
        $this->em->expects(self::never())->method('flush');
        $this->s3->expects(self::never())->method('__call');

        $response = self::getContainer()->get(PurchaseFileController::class)->delete(1, 2, $this->user);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['error' => SpaApiError::PURCHASE_FILE_LOCKED], json_decode($response->getContent(), true));
        self::assertTrue($this->purchase->getFiles()->contains($file));
        self::assertSame($file, $item->getInvoiceFile());
    }

    public function testAllowedDeleteRemovesStorageOnlyAfterCommit(): void
    {
        $file = $this->file(PurchaseFileType::OTHER);
        $this->em->expects(self::exactly(2))->method('refresh')->willReturnCallback(function (object $entity, mixed $mode = null): void {
            self::assertTrue($this->inTransaction);
            if ($entity === $this->purchase) {
                self::assertSame(LockMode::PESSIMISTIC_WRITE, $mode);
            }
        });
        $this->em->expects(self::once())->method('remove')->with($file);
        $this->em->expects(self::once())->method('flush')->willReturnCallback(function (): void {
            self::assertTrue($this->inTransaction);
            self::assertFalse($this->committed);
        });
        $this->s3->expects(self::once())->method('__call')->with('deleteObject', [['Bucket' => 'test', 'Key' => '1/file']])
            ->willReturnCallback(function (): Result {
                self::assertTrue($this->committed);
                self::assertFalse($this->inTransaction);

                return new Result();
            });

        $response = self::getContainer()->get(PurchaseFileController::class)->delete(1, 2, $this->user);

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($this->purchase->getFiles()->contains($file));
    }

    public function testFailedDatabaseDeleteDoesNotRemoveStorage(): void
    {
        $this->file(PurchaseFileType::OTHER);
        $this->em->method('refresh');
        $this->em->expects(self::once())->method('flush')->willThrowException(new \RuntimeException('Database failure'));
        $this->s3->expects(self::never())->method('__call');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database failure');
        self::getContainer()->get(PurchaseFileController::class)->delete(1, 2, $this->user);
    }

    public function testPaymentDecisionUsesTheSameRequestLock(): void
    {
        $payment = $this->stage(PurchaseStagePurpose::PAYMENT, 1, PurchaseStageStatus::ACTIVE);
        $task = new PurchaseApprovalTask();
        $payment->addTask($task)->addTask(new PurchaseApprovalTask());
        $locked = false;
        $this->em->method('refresh')->willReturnCallback(function (object $entity, mixed $mode = null) use (&$locked): void {
            self::assertTrue($this->inTransaction);
            if ($entity === $this->purchase) {
                self::assertSame(LockMode::PESSIMISTIC_WRITE, $mode);
                $locked = true;
            }
        });
        $this->em->expects(self::once())->method('flush')->willReturnCallback(function () use (&$locked, $task): void {
            self::assertTrue($locked);
            self::assertTrue($this->inTransaction);
            self::assertSame(PurchaseTaskDecision::APPROVED, $task->getDecision());
        });

        self::getContainer()->get(PurchaseApprovalWorkflow::class)->approveTask($this->purchase, $task, $this->user);

        self::assertTrue($this->committed);
        self::assertSame(PurchaseStageStatus::ACTIVE, $payment->getStatus());
    }

    public function testPaymentRechecksRequiredFileAfterConcurrentDeletion(): void
    {
        $file = $this->file(PurchaseFileType::INVOICE);
        $item = (new PurchaseRequestItem())->setInvoiceFile($file);
        $this->purchase->addItem($item);
        $payment = $this->stage(PurchaseStagePurpose::PAYMENT, 1, PurchaseStageStatus::ACTIVE);
        $task = (new PurchaseApprovalTask())->setRequiresFileType(PurchaseFileType::INVOICE);
        $payment->addTask($task);
        $this->em->method('refresh')->willReturnCallback(function (object $entity, mixed $mode = null) use ($file, $item): void {
            self::assertTrue($this->inTransaction);
            if ($entity === $this->purchase) {
                self::assertSame(LockMode::PESSIMISTIC_WRITE, $mode);
                // Doctrine refresh перечитывает коллекцию files из БД.
                $this->purchase->removeFile($file);
            } elseif ($entity === $item) {
                $item->setInvoiceFile(null);
            }
        });
        $this->em->expects(self::never())->method('flush');

        try {
            self::getContainer()->get(PurchaseApprovalWorkflow::class)->approveTask($this->purchase, $task, $this->user);
            self::fail('Deleted required invoice must prevent payment.');
        } catch (PurchaseTransitionException $exception) {
            self::assertSame(SpaApiError::PURCHASE_TASK_FILE_REQUIRED, $exception->errorCode);
            self::assertSame(PurchaseTaskDecision::PENDING, $task->getDecision());
            self::assertNull($item->getInvoiceFile());
        }
    }

    private function stage(PurchaseStagePurpose $purpose, int $position, PurchaseStageStatus $status): PurchaseApprovalStage
    {
        $stage = (new PurchaseApprovalStage())->setPurpose($purpose)->setPosition($position)->setStatus($status);
        $this->purchase->addStage($stage);

        return $stage;
    }

    private function file(PurchaseFileType $type): PurchaseRequestFile
    {
        $file = (new PurchaseRequestFile())->setType($type)->setOriginalName('fixture')->setStorageKey('1/file')->setUploadedBy($this->user);
        (new \ReflectionProperty(PurchaseRequestFile::class, 'id'))->setValue($file, 2);
        $this->purchase->addFile($file);

        return $file;
    }
}
