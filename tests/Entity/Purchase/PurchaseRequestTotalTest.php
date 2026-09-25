<?php

declare(strict_types=1);

namespace App\Tests\Entity\Purchase;

use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestItem;
use PHPUnit\Framework\TestCase;

/** Сумма заявки считается в копейках: без ошибок float на копейках и крупных суммах. */
final class PurchaseRequestTotalTest extends TestCase
{
    public function testCentsDoNotDrift(): void
    {
        // Во float 0,1 × 3 + 0,2 = 0,5000000000000001.
        $request = $this->request([['0.10', '3.000'], ['0.20', '1.000']]);

        self::assertSame(50, $request->getTotalAmountKopecks());
        self::assertSame(0.5, $request->getTotalAmount());
    }

    public function testLargeAmountWithFractionalQuantity(): void
    {
        // 12 345 678,99 × 3,333 = 41 148 148,073… → 41 148 148,07.
        $request = $this->request([['12345678.99', '3.333']]);

        self::assertSame(4_114_814_807, $request->getTotalAmountKopecks());
        self::assertSame('41148148.07', json_encode($request->getTotalAmount()));
    }

    public function testHalfKopeckRoundsUpAndExcludedAndApprovedQuantityAreRespected(): void
    {
        $request = $this->request([['0.05', '0.500'], ['100.00', '10.000']]);
        $items = $request->getItems()->toArray();
        $items[1]->setApprovedQuantity('2.000');
        $request->addItem($this->item('999.99', '1.000')->setExcluded(true));

        // 0,05 × 0,5 = 0,025 → 0,03; 100 × 2 (утверждено директором) = 200; снятая позиция не в счёт.
        self::assertSame(20_003, $request->getTotalAmountKopecks());
    }

    /** @param list<array{0: string, 1: string}> $rows цена, количество */
    private function request(array $rows): PurchaseRequest
    {
        $request = new PurchaseRequest();
        foreach ($rows as [$price, $quantity]) {
            $request->addItem($this->item($price, $quantity));
        }

        return $request;
    }

    private function item(string $price, string $quantity): PurchaseRequestItem
    {
        return (new PurchaseRequestItem())
            ->setName('Позиция')
            ->setUnit('шт')
            ->setQuantity($quantity)
            ->setEstimatedPrice($price);
    }
}
