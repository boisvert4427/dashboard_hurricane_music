<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class PrestashopProductBatchProvider
{
    public function __construct(
        private readonly Connection $prestashopConnection,
        private readonly string $prestashopBaseUrl,
    ) {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, after_id: int, limit: int, competitor_id: int, has_more: bool}
     */
    public function getNextBatch(int $competitorId, int $limit = 50, int $afterId = 0, int $langId = 1, int $shopId = 1): array
    {
        $limit = max(1, min(200, $limit));

        $feedTable = 'leo_netrivals_send_feed';
        $finalTable = 'tm2dn_dashboard.competitor_url_final';
        $testResultTable = 'tm2dn_dashboard.competitor_url_test_result';

        $sql = sprintf(
             'SELECT f.id_product,
                    NULLIF(TRIM(COALESCE(f.reference, \'\')), \'\') AS supplier_reference,
                    NULLIF(TRIM(COALESCE(f.ean13, \'\')), \'\') AS ean,
                    NULLIF(TRIM(COALESCE(f.manufacturer_name, \'\')), \'\') AS brand,
                    f.price_tax_incl AS source_price,
                    COALESCE(NULLIF(TRIM(COALESCE(f.product_name, \'\')), \'\'), CONCAT(\'Product \', f.id_product)) AS name
             FROM %s f
             LEFT JOIN %s final_row ON final_row.id = f.id_product AND final_row.competitor_id = :competitor_id
             LEFT JOIN %s tr ON tr.id_product = f.id_product AND tr.competitor_id = :competitor_id
             WHERE final_row.id IS NULL
               AND tr.id_product IS NULL
               AND NULLIF(TRIM(COALESCE(f.reference, \'\')), \'\') IS NOT NULL
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
                'limit' => $limit,
                'lang_id' => $langId,
                'shop_id' => $shopId,
            ],
            [
                'competitor_id' => ParameterType::INTEGER,
                'limit' => ParameterType::INTEGER,
                'lang_id' => ParameterType::INTEGER,
                'shop_id' => ParameterType::INTEGER,
            ]
        );

        $items = array_map(
            function (array $row) use ($competitorId, $shopId): array {
                return [
                    'id_product' => (int) ($row['id_product'] ?? 0),
                    'supplier_reference' => trim((string) ($row['supplier_reference'] ?? '')),
                    'ean' => trim((string) ($row['ean'] ?? '')),
                    'brand' => trim((string) ($row['brand'] ?? '')),
                    'source_price' => isset($row['source_price']) ? (float) $row['source_price'] : null,
                    'name' => trim((string) ($row['name'] ?? '')),
                    'competitor_id' => $competitorId,
                    'source_image_url' => $this->getSourceImageUrl((int) ($row['id_product'] ?? 0), $shopId),
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

    public function countEligibleProducts(int $competitorId, int $afterId = 0, int $langId = 1, int $shopId = 1): int
    {
        $feedTable = 'leo_netrivals_send_feed';
        $finalTable = 'tm2dn_dashboard.competitor_url_final';
        $testResultTable = 'tm2dn_dashboard.competitor_url_test_result';

        $sql = sprintf(
             'SELECT COUNT(f.id_product) AS total
             FROM %s f
             LEFT JOIN %s final_row ON final_row.id = f.id_product AND final_row.competitor_id = :competitor_id
             LEFT JOIN %s tr ON tr.id_product = f.id_product AND tr.competitor_id = :competitor_id
             WHERE final_row.id IS NULL
               AND tr.id_product IS NULL
               AND NULLIF(TRIM(COALESCE(f.reference, \'\')), \'\') IS NOT NULL
               AND LOWER(COALESCE(f.reference, \'\')) NOT LIKE \'%%b-%%\'
               AND LOWER(COALESCE(f.reference, \'\')) NOT LIKE \'%%occas%%\'
               AND LOWER(COALESCE(f.reference, \'\')) NOT LIKE \'%%depv%%\'',
            $feedTable,
            $finalTable,
            $testResultTable
        );

        $total = $this->prestashopConnection->fetchOne(
            $sql,
            [
                'competitor_id' => $competitorId,
                'lang_id' => $langId,
                'shop_id' => $shopId,
            ],
            [
                'competitor_id' => ParameterType::INTEGER,
                'lang_id' => ParameterType::INTEGER,
                'shop_id' => ParameterType::INTEGER,
            ]
        );

        return (int) $total;
    }

    /**
     * @param array<int, int> $productIds
     *
     * @return array<int, array{id_product:int,name:string,brand:?string,source_price:?float,supplier_reference:?string,ean:?string}>
     */
    public function getProductSnapshotsByIds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $value): bool => $value > 0)));
        if ($productIds === []) {
            return [];
        }

        $rows = $this->prestashopConnection->fetchAllAssociative(
            'SELECT f.id_product,
                    NULLIF(TRIM(COALESCE(f.reference, \'\')), \'\') AS supplier_reference,
                    NULLIF(TRIM(COALESCE(f.ean13, \'\')), \'\') AS ean,
                    NULLIF(TRIM(COALESCE(f.manufacturer_name, \'\')), \'\') AS brand,
                    f.price_tax_incl AS source_price,
                    COALESCE(NULLIF(TRIM(COALESCE(f.product_name, \'\')), \'\'), CONCAT(\'Product \', f.id_product)) AS name
             FROM leo_netrivals_send_feed f
             WHERE f.id_product IN (:ids)',
            ['ids' => $productIds],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $snapshots = [];
        foreach ($rows as $row) {
            $productId = (int) ($row['id_product'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $snapshots[$productId] = [
                'id_product' => $productId,
                'name' => trim((string) ($row['name'] ?? '')),
                'brand' => $this->nullableString($row['brand'] ?? null),
                'source_price' => isset($row['source_price']) ? (float) $row['source_price'] : null,
                'supplier_reference' => $this->nullableString($row['supplier_reference'] ?? null),
                'ean' => $this->nullableString($row['ean'] ?? null),
            ];
        }

        return $snapshots;
    }

    /**
     * @return array<int, array{id_product:int,name:string,brand:?string,source_price:?float,supplier_reference:?string,ean:?string}>
     */
    public function searchProducts(string $query, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $normalized = mb_strtolower($query);
        $isNumericId = ctype_digit($query);

        $where = [
            "NULLIF(TRIM(COALESCE(f.reference, '')), '') LIKE :needle",
            "NULLIF(TRIM(COALESCE(f.ean13, '')), '') LIKE :needle",
            "NULLIF(TRIM(COALESCE(f.manufacturer_name, '')), '') LIKE :needle",
            "NULLIF(TRIM(COALESCE(f.product_name, '')), '') LIKE :needle",
        ];
        $params = ['needle' => '%' . $query . '%'];
        $types = ['needle' => ParameterType::STRING];

        if ($isNumericId) {
            $where[] = 'f.id_product = :id_product';
            $params['id_product'] = (int) $query;
            $types['id_product'] = ParameterType::INTEGER;
        }

        $sql = sprintf(
            'SELECT f.id_product,
                    NULLIF(TRIM(COALESCE(f.reference, \'\')), \'\') AS supplier_reference,
                    NULLIF(TRIM(COALESCE(f.ean13, \'\')), \'\') AS ean,
                    NULLIF(TRIM(COALESCE(f.manufacturer_name, \'\')), \'\') AS brand,
                    f.price_tax_incl AS source_price,
                    COALESCE(NULLIF(TRIM(COALESCE(f.product_name, \'\')), \'\'), CONCAT(\'Product \', f.id_product)) AS name
             FROM leo_netrivals_send_feed f
             WHERE %s
             ORDER BY CASE WHEN LOWER(COALESCE(f.product_name, \'\')) LIKE :needle THEN 0 ELSE 1 END,
                      f.id_product DESC
             LIMIT %d',
            implode(' OR ', $where),
            $limit
        );

        $rows = $this->prestashopConnection->fetchAllAssociative($sql, $params, $types);

        $snapshots = [];
        foreach ($rows as $row) {
            $productId = (int) ($row['id_product'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $snapshots[$productId] = [
                'id_product' => $productId,
                'name' => trim((string) ($row['name'] ?? '')),
                'brand' => $this->nullableString($row['brand'] ?? null),
                'source_price' => isset($row['source_price']) ? (float) $row['source_price'] : null,
                'supplier_reference' => $this->nullableString($row['supplier_reference'] ?? null),
                'ean' => $this->nullableString($row['ean'] ?? null),
            ];
        }

        return $snapshots;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function getSourceImageUrl(int $productId, int $shopId): ?string
    {
        if ($productId <= 0 || trim($this->prestashopBaseUrl) === '') {
            return null;
        }

        $sql = <<<'SQL'
            SELECT COALESCE(ishop.id_image, i.id_image) AS id_image
            FROM image i
            LEFT JOIN image_shop ishop
                ON ishop.id_image = i.id_image
               AND ishop.id_shop = :shop_id
            WHERE i.id_product = :product_id
            ORDER BY COALESCE(ishop.cover, i.cover) DESC, i.position ASC, i.id_image ASC
            LIMIT 1
            SQL;

        $idImage = (int) $this->prestashopConnection->fetchOne(
            $sql,
            [
                'product_id' => $productId,
                'shop_id' => $shopId,
            ],
            [
                'product_id' => ParameterType::INTEGER,
                'shop_id' => ParameterType::INTEGER,
            ]
        );

        if ($idImage <= 0) {
            return null;
        }

        $digits = str_split((string) $idImage);

        return rtrim($this->prestashopBaseUrl, '/') . '/img/p/' . implode('/', $digits) . '/' . $idImage . '.jpg';
    }

}
