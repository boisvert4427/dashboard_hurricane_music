<?php

declare(strict_types=1);

namespace App\Service;

final class StockCostCalculator
{
    /**
     * @param array<int, array<string, mixed>> $movements
     * @return array{movements: array<int, array<string, mixed>>, state: array<string, mixed>}
     */
    public function calculate(array $movements): array
    {
        $quantity = 0.0;
        $pamp = 0.0;
        $fifoLots = [];
        $results = [];
        $initialized = false;

        foreach ($movements as $movement) {
            $delta = (float) ($movement['quantity_delta'] ?? 0);
            $sourceStock = (float) ($movement['source_stock_after'] ?? $movement['stock_after'] ?? 0);
            $fallbackCost = $this->positiveCost($movement['source_last_purchase_price'] ?? null);
            $statuses = [];

            if (!$initialized) {
                $openingQuantity = max(0.0, $sourceStock - $delta);
                $openingCost = $fallbackCost
                    ?? $this->positiveCost($movement['reception_unit_cost'] ?? null)
                    ?? 0.0;
                $quantity = $openingQuantity;
                $pamp = $openingCost;
                if ($openingQuantity > 0) {
                    $fifoLots[] = ['quantity' => $openingQuantity, 'unit_cost' => $openingCost];
                    $statuses[] = $openingCost > 0 ? 'opening_cost_last_purchase' : 'opening_cost_missing';
                }
                $initialized = true;
            }

            $pampBefore = $pamp;
            $fifoUnitCost = null;
            $fifoCostTotal = null;
            $pampCostTotal = abs($delta) * $pampBefore;
            $incomingUnitCost = null;

            if ($delta > 0) {
                $incomingUnitCost = $this->resolveIncomingUnitCost($movement, $pampBefore, $statuses);
                $newQuantity = $quantity + $delta;
                $pamp = $newQuantity > 0
                    ? (($quantity * $pampBefore) + ($delta * $incomingUnitCost)) / $newQuantity
                    : $incomingUnitCost;
                $quantity = $newQuantity;
                $fifoLots[] = ['quantity' => $delta, 'unit_cost' => $incomingUnitCost];

                if ($this->isSaleMovement($movement)) {
                    $fifoUnitCost = $incomingUnitCost;
                    $fifoCostTotal = $delta * $incomingUnitCost;
                    $pampCostTotal = $delta * $pampBefore;
                }
            } elseif ($delta < 0) {
                $needed = abs($delta);
                [$fifoCostTotal, $fifoLots, $shortage] = $this->consumeFifo(
                    $fifoLots,
                    $needed,
                    $pampBefore > 0 ? $pampBefore : ($fallbackCost ?? 0.0)
                );
                $fifoUnitCost = $needed > 0 ? $fifoCostTotal / $needed : null;
                $quantity = max(0.0, $quantity - $needed);
                if ($shortage > 0) {
                    $statuses[] = 'fifo_shortage_fallback';
                }
            }

            $quantityDifference = $sourceStock - $quantity;
            if (abs($quantityDifference) > 0.00001) {
                $statuses[] = 'stock_reconciled';
                if ($quantityDifference > 0) {
                    $reconciliationCost = $pamp > 0 ? $pamp : ($fallbackCost ?? 0.0);
                    $fifoLots[] = ['quantity' => $quantityDifference, 'unit_cost' => $reconciliationCost];
                } else {
                    [, $fifoLots] = $this->consumeFifo(
                        $fifoLots,
                        abs($quantityDifference),
                        $pamp > 0 ? $pamp : ($fallbackCost ?? 0.0)
                    );
                }
                $quantity = max(0.0, $sourceStock);
            }

            $fifoStockValue = $this->fifoValue($fifoLots);
            $results[] = [
                ...$movement,
                'incoming_unit_cost' => $incomingUnitCost,
                'calculated_pamp_before' => $pampBefore,
                'calculated_pamp_after' => $pamp,
                'pamp_cost_total' => $pampCostTotal,
                'fifo_unit_cost' => $fifoUnitCost,
                'fifo_cost_total' => $fifoCostTotal,
                'calculated_quantity_after' => $quantity,
                'pamp_stock_value' => $quantity * $pamp,
                'fifo_stock_value' => $fifoStockValue,
                'calculation_status' => $statuses === [] ? 'ok' : implode(',', array_values(array_unique($statuses))),
            ];
        }

        $last = $results === [] ? [] : $results[array_key_last($results)];

        return [
            'movements' => $results,
            'state' => [
                'quantity' => $quantity,
                'calculated_pamp' => $pamp,
                'pamp_stock_value' => $quantity * $pamp,
                'fifo_stock_value' => $this->fifoValue($fifoLots),
                'last_source_stock_history_id' => (int) ($last['source_stock_history_id'] ?? 0),
                'last_movement_date' => $last['movement_date'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $movement
     * @param array<int, string> $statuses
     */
    private function resolveIncomingUnitCost(array $movement, float $currentPamp, array &$statuses): float
    {
        $receptionCost = $this->positiveCost($movement['reception_unit_cost'] ?? null);
        if ($receptionCost !== null && $this->isReceptionMovement($movement)) {
            return $receptionCost;
        }

        if ($this->isReceptionMovement($movement)) {
            $statuses[] = 'reception_cost_missing';
        } else {
            $statuses[] = 'non_reception_inbound';
        }

        $lastPurchasePrice = $this->positiveCost($movement['source_last_purchase_price'] ?? null);
        if ($lastPurchasePrice !== null) {
            $statuses[] = 'incoming_cost_last_purchase';
            return $lastPurchasePrice;
        }

        if ($currentPamp > 0) {
            $statuses[] = 'incoming_cost_current_pamp';
            return $currentPamp;
        }

        $statuses[] = 'incoming_cost_missing';

        return 0.0;
    }

    /**
     * @param array<int, array{quantity: float, unit_cost: float}> $lots
     * @return array{0: float, 1: array<int, array{quantity: float, unit_cost: float}>, 2: float}
     */
    private function consumeFifo(array $lots, float $needed, float $fallbackCost): array
    {
        $cost = 0.0;
        while ($needed > 0.00001 && $lots !== []) {
            $lot = array_shift($lots);
            $taken = min($needed, $lot['quantity']);
            $cost += $taken * $lot['unit_cost'];
            $needed -= $taken;
            $remaining = $lot['quantity'] - $taken;
            if ($remaining > 0.00001) {
                array_unshift($lots, ['quantity' => $remaining, 'unit_cost' => $lot['unit_cost']]);
            }
        }

        $shortage = max(0.0, $needed);
        if ($shortage > 0) {
            $cost += $shortage * $fallbackCost;
        }

        return [$cost, $lots, $shortage];
    }

    /** @param array<int, array{quantity: float, unit_cost: float}> $lots */
    private function fifoValue(array $lots): float
    {
        return array_reduce(
            $lots,
            static fn (float $value, array $lot): float => $value + ($lot['quantity'] * $lot['unit_cost']),
            0.0
        );
    }

    /** @param array<string, mixed> $movement */
    private function isReceptionMovement(array $movement): bool
    {
        return strtoupper((string) ($movement['nature'] ?? '')) === 'R';
    }

    /** @param array<string, mixed> $movement */
    private function isSaleMovement(array $movement): bool
    {
        return strtoupper((string) ($movement['nature'] ?? '')) === 'V';
    }

    private function positiveCost(mixed $value): ?float
    {
        $cost = is_numeric($value) ? (float) $value : 0.0;

        return $cost > 0 ? $cost : null;
    }
}
