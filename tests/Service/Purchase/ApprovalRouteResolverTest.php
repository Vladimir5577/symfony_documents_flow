<?php

declare(strict_types=1);

namespace App\Tests\Service\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestItem;
use App\Entity\Purchase\PurchaseRouteDefault;
use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Entity\Purchase\PurchaseRouteTemplateStage;
use App\Entity\Purchase\PurchaseRouteTemplateTask;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Enum\Purchase\PurchaseTaskAssignment;
use App\Repository\Purchase\PurchaseRouteDefaultRepository;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;
use App\Service\Purchase\ApprovalRouteResolver;
use App\Service\Purchase\PurchaseTransitionException;
use PHPUnit\Framework\TestCase;

/**
 * Ускоренная процедура: заявка дешевле порога маршрута идёт им сама (только
 * финансовый директор), от порога — обычным путём через генерального директора.
 */
final class ApprovalRouteResolverTest extends TestCase
{
    private PurchaseRouteTemplate $fast;
    private PurchaseRouteTemplate $standard;

    protected function setUp(): void
    {
        $this->fast = $this->route('FAST_TRACK', PurchaseRoleCode::FINANCE_DIRECTOR)->setMaxAmountKopecks(1_500_000);
        $this->standard = $this->route('STANDARD', PurchaseRoleCode::DIRECTOR);
    }

    public function testCheapPurchaseGoesFastTrackWhateverTheButton(): void
    {
        foreach ([PurchaseRequestKind::STANDARD, PurchaseRequestKind::FAST] as $kind) {
            self::assertSame($this->fast, $this->resolver()->resolve($this->purchase($kind, '14999.99')), $kind->value);
        }
    }

    public function testFromThresholdPurchaseGoesThroughTheDirector(): void
    {
        self::assertSame($this->standard, $this->resolver()->resolve($this->purchase(PurchaseRequestKind::STANDARD, '15000.00')));
        self::assertNotContains($this->fast, $this->resolver()->options($this->purchase(PurchaseRequestKind::STANDARD, '20000.00')));
    }

    public function testLowestFittingThresholdWins(): void
    {
        $petty = $this->route('PETTY', PurchaseRoleCode::FINANCE_DIRECTOR)->setMaxAmountKopecks(300_000);

        self::assertSame($petty, $this->resolver([$this->fast, $petty, $this->standard])->resolve($this->purchase(PurchaseRequestKind::STANDARD, '1000.00')));
        self::assertSame($this->fast, $this->resolver([$this->fast, $petty, $this->standard])->resolve($this->purchase(PurchaseRequestKind::STANDARD, '5000.00')));
    }

    public function testAssignedRouteStillWins(): void
    {
        $purchase = $this->purchase(PurchaseRequestKind::STANDARD, '100.00')->setRouteTemplate($this->standard);

        self::assertSame($this->standard, $this->resolver()->resolve($purchase));
    }

    public function testFastTrackAsDefaultDoesNotLetExpensivePurchaseBypassTheDirector(): void
    {
        $this->expectException(PurchaseTransitionException::class);
        $this->expectExceptionMessage(SpaApiError::PURCHASE_ROUTE_NOT_CONFIGURED);

        $this->resolver(default: $this->fast)->resolve($this->purchase(PurchaseRequestKind::FAST, '50000.00'));
    }

    /** @param list<PurchaseRouteTemplate>|null $templates */
    private function resolver(?array $templates = null, ?PurchaseRouteTemplate $default = null): ApprovalRouteResolver
    {
        $repo = $this->createStub(PurchaseRouteTemplateRepository::class);
        $repo->method('findActiveForKind')->willReturn($templates ?? [$this->fast, $this->standard]);

        $defaults = $this->createStub(PurchaseRouteDefaultRepository::class);
        $defaults->method('findByKind')->willReturnCallback(
            fn (PurchaseRequestKind $kind): PurchaseRouteDefault => (new PurchaseRouteDefault())
                ->setKind($kind)
                ->setTemplate($default ?? $this->standard),
        );

        return new ApprovalRouteResolver($repo, $defaults);
    }

    private function route(string $code, PurchaseRoleCode $role): PurchaseRouteTemplate
    {
        return (new PurchaseRouteTemplate())
            ->setCode($code)
            ->setName($code)
            ->setAllowedKinds([PurchaseRequestKind::STANDARD, PurchaseRequestKind::FAST])
            ->addStage((new PurchaseRouteTemplateStage())
                ->setPosition(1)
                ->setPurpose(PurchaseStagePurpose::SIGN_OFF)
                ->addTask((new PurchaseRouteTemplateTask())
                    ->setPosition(1)
                    ->setAssignmentType(PurchaseTaskAssignment::ROLE)
                    ->setRoleCode($role)));
    }

    private function purchase(PurchaseRequestKind $kind, string $price): PurchaseRequest
    {
        return (new PurchaseRequest())
            ->setCreatedAs($kind)
            ->addItem((new PurchaseRequestItem())->setName('Бумага')->setUnit('шт')->setQuantity('1.000')->setEstimatedPrice($price));
    }
}
