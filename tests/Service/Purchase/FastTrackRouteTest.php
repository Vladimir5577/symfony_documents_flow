<?php

declare(strict_types=1);

namespace App\Tests\Service\Purchase;

use App\Entity\Purchase\PurchaseApprovalTask;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestItem;
use App\Entity\Purchase\PurchaseRouteDefault;
use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Entity\Purchase\PurchaseRouteTemplateStage;
use App\Entity\Purchase\PurchaseRouteTemplateTask;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseHistoryAction;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseTaskAssignment;
use App\Repository\Purchase\PurchaseRouteDefaultRepository;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;
use App\Service\Notification\NotificationPublisher;
use App\Service\Purchase\ApprovalRouteBuilder;
use App\Service\Purchase\ApprovalRouteResolver;
use App\Service\Purchase\PurchaseApprovalWorkflow;
use App\Service\Purchase\PurchaseHistoryLogger;
use App\Service\Purchase\PurchaseNotificationPublisher;
use App\Service\Purchase\PurchaseRequestEditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Ускоренная процедура до 15 000 ₽: закупки оценивают, финансовый директор
 * согласует и оплачивает. Если после оценки сумма не меньше порога — заявка
 * уходит обычным маршрутом, через генерального директора.
 */
final class FastTrackRouteTest extends TestCase
{
    use PurchaseNotificationAssertions;

    private PurchaseApprovalWorkflow $workflow;
    private PurchaseRouteTemplate $fastTrack;
    private PurchaseRouteTemplate $standard;

    protected function setUp(): void
    {
        $this->fastTrack = $this->route('FAST_TRACK', [
            [PurchaseStagePurpose::SOURCING, PurchaseRoleCode::PURCHASE_DEPARTMENT],
            [PurchaseStagePurpose::PAYMENT, PurchaseRoleCode::FINANCE_DIRECTOR],
        ])->setMaxAmountKopecks(1_500_000);
        $this->standard = $this->route('STANDARD', [
            [PurchaseStagePurpose::TRIAGE, PurchaseRoleCode::DIRECTOR],
            [PurchaseStagePurpose::SOURCING, PurchaseRoleCode::PURCHASE_DEPARTMENT],
            [PurchaseStagePurpose::SIGN_OFF, PurchaseRoleCode::DIRECTOR],
            [PurchaseStagePurpose::PAYMENT, PurchaseRoleCode::FINANCE_DIRECTOR],
        ]);
        $this->workflow = $this->workflow();
    }

    public function testPurchaseWithoutPricesStartsFastTrackAtPurchasing(): void
    {
        $request = $this->submitted();

        self::assertSame('FAST_TRACK', $request->getAppliedRouteTemplate()?->getCode());
        self::assertSame(PurchaseStagePurpose::SOURCING, $request->getCurrentStage()?->getPurpose());
    }

    public function testCheapAfterPricingGoesToFinanceDirectorAndAuthorHearsAboutPayment(): void
    {
        $request = $this->submitted();
        $purchaser = $this->user(102);

        $this->workflow->approveTask($request, $this->activeTask($request), $purchaser, null, $this->price($request, '3000'));
        self::assertSame(PurchaseStagePurpose::PAYMENT, $request->getCurrentStage()?->getPurpose());
        self::assertSame('FAST_TRACK', $request->getAppliedRouteTemplate()?->getCode(), 'генеральный директор не нужен');

        $this->purchaseNotifications = [];
        $this->workflow->approveTask($request, $this->activeTask($request), $this->user(103));
        self::assertSame(PurchaseStatus::INVOICE_PAID, $request->getStatus());
        self::assertContains('payment_confirmed', array_column($this->purchaseNotifications, 1));
    }

    public function testExpensiveAfterPricingIsReroutedThroughTheDirector(): void
    {
        $request = $this->submitted();

        $this->workflow->approveTask($request, $this->activeTask($request), $this->user(102), null, $this->price($request, '20000'));

        self::assertSame('STANDARD', $request->getAppliedRouteTemplate()?->getCode());
        self::assertSame(PurchaseStagePurpose::TRIAGE, $request->getCurrentStage()?->getPurpose(), 'сначала генеральный директор');
        self::assertSame(PurchaseStatus::ON_APPROVAL, $request->getStatus());
        self::assertSame('2000.00', $request->getItems()->first()->getEstimatedPrice(), 'цена закупок сохранилась');
        self::assertSame(2_000_000, $request->getTotalAmountKopecks());
        self::assertContains(PurchaseHistoryAction::ROUTE_CHANGED, array_map(
            static fn ($entry) => $entry->getAction(),
            $request->getHistory()->toArray(),
        ));
        self::assertContains('stage_activated', array_column($this->purchaseNotifications, 1));
    }

    // Обвязка

    private function workflow(): PurchaseApprovalWorkflow
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $work): mixed => $work($em));

        $templates = $this->createStub(PurchaseRouteTemplateRepository::class);
        $templates->method('findActiveForKind')->willReturn([$this->fastTrack, $this->standard]);
        $defaults = $this->createStub(PurchaseRouteDefaultRepository::class);
        $defaults->method('findByKind')->willReturnCallback(
            fn (PurchaseRequestKind $kind): PurchaseRouteDefault => (new PurchaseRouteDefault())->setKind($kind)->setTemplate($this->standard),
        );

        $history = new PurchaseHistoryLogger($em);

        return new PurchaseApprovalWorkflow(
            $em,
            new PurchaseNotificationPublisher(new NotificationPublisher($this->capturePurchaseBus()), $this->purchaseRoster(), $this->purchaseUsers()),
            new ApprovalRouteResolver($templates, $defaults),
            new ApprovalRouteBuilder($em),
            $history,
            new PurchaseRequestEditor($em, $history),
        );
    }

    /** Быстрая заявка из витрины: цен нет, их поставят закупки. */
    private function submitted(): PurchaseRequest
    {
        $author = $this->user(1);
        $item = (new PurchaseRequestItem())->setName('Бумага А4')->setUnit('пачка')->setQuantity('10.000')->setEstimatedPrice('0.00');
        self::setId($item, 1);
        $request = (new PurchaseRequest())->setTitle('Канцелярия')->setCreatedBy($author)->setCreatedAs(PurchaseRequestKind::FAST);
        self::setId($request, 700);
        $request->addItem($item);

        $this->workflow->submit($request, $author);
        $this->assignIds($request);

        return $request;
    }

    /** Цены закупок: сумма заявки $total, позиция одна — 10 пачек. */
    private function price(PurchaseRequest $request, string $total): array
    {
        return [(int) $request->getItems()->first()->getId() => [
            'included' => true,
            'quantity' => null,
            'price' => (string) ((float) $total / 10),
        ]];
    }

    private function activeTask(PurchaseRequest $request): PurchaseApprovalTask
    {
        $task = $request->getCurrentStage()?->getPendingTasks()[0] ?? null;
        self::assertInstanceOf(PurchaseApprovalTask::class, $task);

        return $task;
    }

    /** @param list<array{0: PurchaseStagePurpose, 1: PurchaseRoleCode}> $stages */
    private function route(string $code, array $stages): PurchaseRouteTemplate
    {
        $template = (new PurchaseRouteTemplate())
            ->setCode($code)
            ->setName($code)
            ->setAllowedKinds([PurchaseRequestKind::STANDARD, PurchaseRequestKind::FAST]);
        foreach ($stages as $position => [$purpose, $role]) {
            $template->addStage((new PurchaseRouteTemplateStage())
                ->setPosition($position + 1)
                ->setPurpose($purpose)
                ->setAllowsReject(true)
                ->addTask((new PurchaseRouteTemplateTask())
                    ->setPosition(1)
                    ->setAssignmentType(PurchaseTaskAssignment::ROLE)
                    ->setRoleCode($role)));
        }

        return $template;
    }

    private function assignIds(PurchaseRequest $request): void
    {
        $stageId = 1000;
        $taskId = 2000;
        foreach ($request->getStages() as $stage) {
            self::setId($stage, ++$stageId);
            foreach ($stage->getTasks() as $task) {
                self::setId($task, ++$taskId);
            }
        }
    }

    private function user(int $id): User
    {
        $user = new User();
        self::setId($user, $id);

        return $user;
    }

    private static function setId(object $entity, int $id): void
    {
        $class = new \ReflectionClass($entity);
        while (!$class->hasProperty('id')) {
            $parent = $class->getParentClass();
            self::assertNotFalse($parent);
            $class = $parent;
        }
        $class->getProperty('id')->setValue($entity, $id);
    }
}
