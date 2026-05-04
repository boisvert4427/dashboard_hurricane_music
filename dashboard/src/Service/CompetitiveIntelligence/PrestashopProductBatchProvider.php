<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class PrestashopProductBatchProvider
{
    public function __construct(
        private readonly Connection $prestashopConnection,
    ) {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, after_id: int, limit: int, competitor_id: int, has_more: bool}
     */
    public function getNextBatch(int $competitorId, int $limit = 50, int $afterId = 0, int $langId = 1, int $shopId = 1): array
    {
        $limit = max(1, min(200, $limit));
        $afterId = max(0, $afterId);

        $feedTable = 'leo_netrivals_send_feed';
        $finalTable = 'tm2dn_dashboard.competitor_url_final';
        $testResultTable = 'tm2dn_dashboard.competitor_url_test_result';

        $sql = sprintf(
            'SELECT f.id_product,
                    NULLIF(TRIM(COALESCE(f.reference, \'\')), \'\') AS supplier_reference,
                    NULLIF(TRIM(COALESCE(f.ean13, \'\')), \'\') AS ean,
                    NULLIF(TRIM(COALESCE(f.manufacturer_name, \'\')), \'\') AS brand,
                    COALESCE(NULLIF(TRIM(COALESCE(f.product_name, \'\')), \'\'), CONCAT(\'Product \', f.id_product)) AS name
             FROM %s f
             LEFT JOIN %s final_row ON final_row.id = f.id_product AND final_row.competitor_id = :competitor_id
             LEFT JOIN %s tr ON tr.id_product = f.id_product AND tr.competitor_id = :competitor_id
             WHERE f.id_product > :after_id
               AND final_row.id IS NULL
               AND NULLIF(TRIM(COALESCE(f.reference, \'\')), \'\') IS NOT NULL
               AND (tr.result IS NULL OR tr.result NOT IN (\'not_found\', \'cloudflare\', \'search_input_not_found\'))
               AND LOWER(COALESCE(f.reference, \'\')) NOT LIKE \'%%b-%%\'
               AND LOWER(COALESCE(f.reference, \'\')) NOT LIKE \'%%occas%%\'
               AND LOWER(COALESCE(f.reference, \'\')) NOT LIKE \'%%depv%%\'
             ORDER BY f.id_product ASC
             LIMIT :limit',
            $feedTable,
            $finalTable,
            $testResultTable
        );

        $rows = $this->prestashopConnection->fetchAllAssociative(
            $sql,
            [
                'competitor_id' => $competitorId,
                'after_id' => $afterId,
                'limit' => $limit,
                'lang_id' => $langId,
                'shop_id' => $shopId,
            ],
            [
                'competitor_id' => ParameterType::INTEGER,
                'after_id' => ParameterType::INTEGER,
                'limit' => ParameterType::INTEGER,
                'lang_id' => ParameterType::INTEGER,
                'shop_id' => ParameterType::INTEGER,
            ]
        );

        $items = array_map(
            static function (array $row) use ($competitorId): array {
                return [
                    'id_product' => (int) ($row['id_product'] ?? 0),
                    'supplier_reference' => trim((string) ($row['supplier_reference'] ?? '')),
                    'ean' => trim((string) ($row['ean'] ?? '')),
                    'brand' => trim((string) ($row['brand'] ?? '')),
                    'name' => trim((string) ($row['name'] ?? '')),
                    'competitor_id' => $competitorId,
                ];
            },
            $rows
        );

        $lastId = $afterId;
        foreach ($items as $item) {
            $lastId = max($lastId, (int) $item['id_product']);
        }

        return [
            'items' => $items,
            'after_id' => $lastId,
            'limit' => $limit,
            'competitor_id' => $competitorId,
            'has_more' => count($items) === $limit,
        ];
    }

}
