<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class InvoicePampMarginService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.reporting_connection')]
        private readonly Connection $reportingConnection,
    ) {
    }

    public function syncAll(): int
    {
        return $this->sync();
    }

    public function syncPair(int $productId, int $siteId): int
    {
        return $this->sync($productId, $siteId);
    }

    private function sync(?int $productId = null, ?int $siteId = null): int
    {
        $movementWhere = "WHERE UPPER(TRIM(piece)) IN ('VENTE', 'ANNUL VENTE', 'AVOIR')";
        $invoiceWhere = 'WHERE r.source_site_id IS NOT NULL';
        $params = [];

        if ($productId !== null && $siteId !== null) {
            $movementWhere .= ' AND product_id = :product_id AND site_id = :site_id';
            $invoiceWhere .= ' AND r.product_id = :product_id AND r.source_site_id = :site_id';
            $params = ['product_id' => $productId, 'site_id' => $siteId];
        }

        $this->reportingConnection->executeStatement(
            <<<SQL
                UPDATE reporting_invoice_line_fact r
                SET
                    r.margin_ht = COALESCE(r.source_margin_ht, r.margin_ht),
                    r.pamp_unit_cost = NULL,
                    r.pamp_cost_total = NULL
                {$invoiceWhere}
            SQL,
            $params
        );

        $this->reportingConnection->executeStatement(
            <<<SQL
                UPDATE reporting_invoice_line_fact r
                SET r.margin_ht = r.total_ht * 0.25
                {$invoiceWhere}
                    AND (
                        UPPER(COALESCE(r.product_code, '')) LIKE 'DEPV%'
                        OR UPPER(COALESCE(r.supplier_reference, '')) LIKE 'DEPV%'
                    )
            SQL,
            $params
        );

        return $this->reportingConnection->executeStatement(
            <<<SQL
                UPDATE reporting_invoice_line_fact r
                INNER JOIN (
                    SELECT
                        source_piece_id,
                        product_id,
                        site_id,
                        SUM(pamp_cost_total) / NULLIF(SUM(ABS(quantity_delta)), 0) AS pamp_unit_cost
                    FROM reporting_stock_movement_cost
                    {$movementWhere}
                    GROUP BY source_piece_id, product_id, site_id
                ) costs
                    ON costs.source_piece_id = r.source_invoice_id
                    AND costs.product_id = r.product_id
                    AND costs.site_id = r.source_site_id
                SET
                    r.pamp_unit_cost = costs.pamp_unit_cost,
                    r.pamp_cost_total = r.quantity * costs.pamp_unit_cost,
                    r.margin_ht = r.total_ht - (r.quantity * costs.pamp_unit_cost)
                {$invoiceWhere}
                    AND costs.pamp_unit_cost > 0
            SQL,
            $params
        );
    }
}
