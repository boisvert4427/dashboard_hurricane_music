<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\StockCostCalculator;
use PHPUnit\Framework\TestCase;

final class StockCostCalculatorTest extends TestCase
{
    public function testItCalculatesFifoAndPampIndependently(): void
    {
        $calculator = new StockCostCalculator();
        $result = $calculator->calculate([
            $this->movement(1, '2026-01-01 10:00:00', 10, 10, 'R', 100.0),
            $this->movement(2, '2026-01-10 10:00:00', 15, 5, 'R', 120.0),
            $this->movement(3, '2026-01-15 10:00:00', 3, -12, 'V', null),
        ]);

        self::assertCount(3, $result['movements']);
        $sale = $result['movements'][2];
        self::assertEqualsWithDelta(106.666666, $sale['calculated_pamp_before'], 0.00001);
        self::assertEqualsWithDelta(1280.0, $sale['pamp_cost_total'], 0.00001);
        self::assertEqualsWithDelta(103.333333, $sale['fifo_unit_cost'], 0.00001);
        self::assertEqualsWithDelta(1240.0, $sale['fifo_cost_total'], 0.00001);
        self::assertEqualsWithDelta(3.0, $result['state']['quantity'], 0.00001);
        self::assertEqualsWithDelta(320.0, $result['state']['pamp_stock_value'], 0.00001);
        self::assertEqualsWithDelta(360.0, $result['state']['fifo_stock_value'], 0.00001);
    }

    public function testItSeedsAnExistingOpeningStockWithoutUsingSourcePmap(): void
    {
        $calculator = new StockCostCalculator();
        $movement = $this->movement(10, '2026-01-01 10:00:00', 4, -1, 'V', null);
        $movement['source_last_purchase_price'] = 50.0;

        $result = $calculator->calculate([$movement]);
        $sale = $result['movements'][0];

        self::assertEqualsWithDelta(50.0, $sale['calculated_pamp_before'], 0.00001);
        self::assertEqualsWithDelta(50.0, $sale['fifo_unit_cost'], 0.00001);
        self::assertStringContainsString('opening_cost_last_purchase', $sale['calculation_status']);
    }

    public function testStockEntryWithoutReceptionUsesLastPurchasePriceBeforeCurrentPamp(): void
    {
        $calculator = new StockCostCalculator();
        $entry = $this->movement(2, '2026-01-02 10:00:00', 15, 5, 'M', null);
        $entry['piece'] = 'ENTREE STOCK';
        $entry['source_last_purchase_price'] = 80.0;

        $result = $calculator->calculate([
            $this->movement(1, '2026-01-01 10:00:00', 10, 10, 'R', 100.0),
            $entry,
        ]);

        $calculatedEntry = $result['movements'][1];
        self::assertEqualsWithDelta(80.0, $calculatedEntry['incoming_unit_cost'], 0.00001);
        self::assertEqualsWithDelta(93.333333, $calculatedEntry['calculated_pamp_after'], 0.00001);
        self::assertStringContainsString('incoming_cost_last_purchase', $calculatedEntry['calculation_status']);
    }

    /** @return array<string, mixed> */
    private function movement(int $id, string $date, float $stockAfter, float $delta, string $nature, ?float $receptionCost): array
    {
        return [
            'source_stock_history_id' => $id,
            'movement_date' => $date,
            'product_id' => 123,
            'site_id' => 0,
            'source_piece_id' => $id,
            'nature' => $nature,
            'piece' => $nature === 'R' ? 'RECEPTION' : 'VENTE',
            'quantity_delta' => $delta,
            'stock_after' => $stockAfter,
            'source_last_purchase_price' => 90.0,
            'reception_unit_cost' => $receptionCost,
        ];
    }
}
