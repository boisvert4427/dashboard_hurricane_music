<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class StockCostCacheService
{
    private const CHECKPOINT_NAME = 'stock_cost_source_discovery_site_';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.reporting_connection')]
        private readonly Connection $reportingConnection,
        #[Autowire(service: 'doctrine.dbal.prestashop_connection')]
        private readonly Connection $prestashopConnection,
        private readonly StockCostCalculator $calculator,
    ) {
    }

    /**
     * @return array{discovered_movements:int, queued_pairs:int, processed_pairs:int, processed_movements:int, failed_pairs:int}
     */
    public function run(int $pairLimit = 100, int $discoveryLimit = 5000): array
    {
        $discovery = $this->discoverChanges($discoveryLimit);
        $queue = $this->reportingConnection->fetchAllAssociative(
            'SELECT product_id, site_id FROM reporting_stock_recalc_queue ORDER BY queued_at ASC, product_id ASC, site_id ASC LIMIT ' . max(1, $pairLimit)
        );

        $processedPairs = 0;
        $processedMovements = 0;
        $failedPairs = 0;

        foreach ($queue as $pair) {
            $productId = (int) $pair['product_id'];
            $siteId = (int) $pair['site_id'];
            try {
                $processedMovements += $this->recalculatePair($productId, $siteId);
                ++$processedPairs;
            } catch (\Throwable $e) {
                ++$failedPairs;
                $this->reportingConnection->executeStatement(
                    'UPDATE reporting_stock_recalc_queue SET attempts = attempts + 1, last_error = :error WHERE product_id = :product_id AND site_id = :site_id',
                    [
                        'error' => mb_substr($e->getMessage(), 0, 4000),
                        'product_id' => $productId,
                        'site_id' => $siteId,
                    ]
                );
            }
        }

        return [
            'discovered_movements' => $discovery['movements'],
            'queued_pairs' => $discovery['pairs'],
            'processed_pairs' => $processedPairs,
            'processed_movements' => $processedMovements,
            'failed_pairs' => $failedPairs,
        ];
    }

    /** @return array{movements:int, pairs:int} */
    private function discoverChanges(int $limit): array
    {
        $sites = array_map(
            static fn (array $row): int => (int) $row['SITE'],
            $this->prestashopConnection->fetchAllAssociative('SELECT DISTINCT SITE FROM K_HISTO_STOCK ORDER BY SITE')
        );
        $perSiteLimit = max(1, (int) ceil($limit / max(1, count($sites))));
        $rows = [];
        $siteCheckpoints = [];
        foreach ($sites as $siteId) {
            $checkpointName = self::CHECKPOINT_NAME . $siteId;
            $checkpoint = (int) $this->reportingConnection->fetchOne(
                'SELECT COALESCE(MAX(checkpoint_value), 0) FROM reporting_etl_checkpoint WHERE process_name = :name',
                ['name' => $checkpointName]
            );
            $siteRows = $this->prestashopConnection->fetchAllAssociative(
                sprintf(
                    'SELECT IDHISTO_STOCK, IDART, SITE FROM K_HISTO_STOCK WHERE SITE = :site_id AND IDHISTO_STOCK > :checkpoint ORDER BY IDHISTO_STOCK ASC LIMIT %d',
                    $perSiteLimit
                ),
                ['site_id' => $siteId, 'checkpoint' => $checkpoint]
            );
            $rows = array_merge($rows, $siteRows);
            $siteCheckpoints[$siteId] = $siteRows === []
                ? $checkpoint
                : max(array_map(static fn (array $row): int => (int) $row['IDHISTO_STOCK'], $siteRows));
        }

        if ($rows === []) {
            return ['movements' => 0, 'pairs' => 0];
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $pairs = [];
        foreach ($rows as $row) {
            $sourceId = (int) $row['IDHISTO_STOCK'];
            $productId = (int) $row['IDART'];
            $siteId = (int) $row['SITE'];
            if ($productId <= 0) {
                continue;
            }
            $key = $productId . ':' . $siteId;
            if (!isset($pairs[$key]) || $sourceId < $pairs[$key][2]) {
                $pairs[$key] = [$productId, $siteId, $sourceId];
            }
        }

        $this->reportingConnection->transactional(function (Connection $connection) use ($pairs, $now, $siteCheckpoints): void {
            foreach ($pairs as [$productId, $siteId, $sourceId]) {
                $connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO reporting_stock_recalc_queue
                            (product_id, site_id, first_changed_source_id, queued_at, attempts, last_error)
                        VALUES
                            (:product_id, :site_id, :source_id, :queued_at, 0, NULL)
                        ON DUPLICATE KEY UPDATE
                            first_changed_source_id = LEAST(first_changed_source_id, VALUES(first_changed_source_id)),
                            queued_at = LEAST(queued_at, VALUES(queued_at))
                    SQL,
                    [
                        'product_id' => $productId,
                        'site_id' => $siteId,
                        'source_id' => $sourceId,
                        'queued_at' => $now,
                    ]
                );
            }

            foreach ($siteCheckpoints as $siteId => $checkpoint) {
                $connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO reporting_etl_checkpoint (process_name, checkpoint_value, updated_at)
                        VALUES (:name, :checkpoint, :updated_at)
                        ON DUPLICATE KEY UPDATE checkpoint_value = VALUES(checkpoint_value), updated_at = VALUES(updated_at)
                    SQL,
                    ['name' => self::CHECKPOINT_NAME . $siteId, 'checkpoint' => $checkpoint, 'updated_at' => $now]
                );
            }
        });

        return ['movements' => count($rows), 'pairs' => count($pairs)];
    }

    private function recalculatePair(int $productId, int $siteId): int
    {
        $sourceRows = $this->prestashopConnection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    h.IDHISTO_STOCK AS source_stock_history_id,
                    h.Date AS movement_date,
                    h.IDART AS product_id,
                    h.SITE AS site_id,
                    h.IDPIECE AS source_piece_id,
                    h.nature,
                    h.PIECE AS piece,
                    h.Q_VAR AS quantity_delta,
                    h.Q_STOCK AS source_stock_after,
                    h.DER_PA AS source_last_purchase_price,
                    receipts.reception_unit_cost
                FROM K_HISTO_STOCK h
                LEFT JOIN (
                    SELECT
                        IDRECEPTION,
                        IDART,
                        SUM(
                            Q_LIV * GREATEST(
                                0,
                                (PRIXHT - RemiseMontant - RemiseSupltMontant)
                                * (1 - (Remise / 100))
                                * (1 - (RemiseSuplt / 100))
                            )
                        ) / NULLIF(SUM(Q_LIV), 0) AS reception_unit_cost
                    FROM K_LI_RECEPT
                    WHERE IDART = :receipt_product_id
                    GROUP BY IDRECEPTION, IDART
                ) receipts ON receipts.IDRECEPTION = h.IDPIECE AND receipts.IDART = h.IDART
                WHERE h.IDART = :product_id AND h.SITE = :site_id
                ORDER BY h.Date ASC, h.IDHISTO_STOCK ASC
            SQL,
            [
                'receipt_product_id' => $productId,
                'product_id' => $productId,
                'site_id' => $siteId,
            ]
        );

        $calculation = $this->calculator->calculate($sourceRows);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->reportingConnection->transactional(function (Connection $connection) use ($productId, $siteId, $calculation, $now): void {
            $connection->executeStatement(
                'DELETE FROM reporting_stock_movement_cost WHERE product_id = :product_id AND site_id = :site_id',
                ['product_id' => $productId, 'site_id' => $siteId]
            );

            foreach (array_chunk($calculation['movements'], 250) as $chunk) {
                $this->insertMovementChunk($connection, $chunk, $now);
            }

            $state = $calculation['state'];
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO reporting_stock_current_cost
                        (product_id, site_id, quantity, calculated_pamp, pamp_stock_value, fifo_stock_value,
                         last_source_stock_history_id, last_movement_date, calculated_at)
                    VALUES
                        (:product_id, :site_id, :quantity, :calculated_pamp, :pamp_stock_value, :fifo_stock_value,
                         :last_source_stock_history_id, :last_movement_date, :calculated_at)
                    ON DUPLICATE KEY UPDATE
                        quantity = VALUES(quantity), calculated_pamp = VALUES(calculated_pamp),
                        pamp_stock_value = VALUES(pamp_stock_value), fifo_stock_value = VALUES(fifo_stock_value),
                        last_source_stock_history_id = VALUES(last_source_stock_history_id),
                        last_movement_date = VALUES(last_movement_date), calculated_at = VALUES(calculated_at)
                SQL,
                [
                    'product_id' => $productId,
                    'site_id' => $siteId,
                    'quantity' => $state['quantity'],
                    'calculated_pamp' => $state['calculated_pamp'],
                    'pamp_stock_value' => $state['pamp_stock_value'],
                    'fifo_stock_value' => $state['fifo_stock_value'],
                    'last_source_stock_history_id' => $state['last_source_stock_history_id'],
                    'last_movement_date' => $state['last_movement_date'],
                    'calculated_at' => $now,
                ]
            );
            $connection->executeStatement(
                'DELETE FROM reporting_stock_recalc_queue WHERE product_id = :product_id AND site_id = :site_id',
                ['product_id' => $productId, 'site_id' => $siteId]
            );
        });

        return count($calculation['movements']);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function insertMovementChunk(Connection $connection, array $rows, string $now): void
    {
        if ($rows === []) {
            return;
        }

        $columns = [
            'source_stock_history_id', 'movement_date', 'product_id', 'site_id', 'source_piece_id', 'nature', 'piece',
            'quantity_delta', 'source_stock_after', 'source_last_purchase_price', 'reception_unit_cost', 'incoming_unit_cost',
            'calculated_pamp_before', 'calculated_pamp_after', 'pamp_cost_total', 'fifo_unit_cost', 'fifo_cost_total',
            'calculated_quantity_after', 'pamp_stock_value', 'fifo_stock_value', 'calculation_status', 'calculated_at',
        ];
        $placeholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $sql = 'INSERT INTO reporting_stock_movement_cost (' . implode(',', $columns) . ') VALUES '
            . implode(',', array_fill(0, count($rows), $placeholder));
        $params = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $params[] = $column === 'calculated_at' ? $now : ($row[$column] ?? null);
            }
        }
        $connection->executeStatement($sql, $params);
    }
}
