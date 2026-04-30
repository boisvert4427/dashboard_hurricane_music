<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class PrestashopProductBatchProvider
{
    /** @var array<string, string> */
    private array $prefixCache = [];

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

        $prefix = $this->resolvePrefix();
        $productTable = $prefix . 'product';
        $productLangTable = $prefix . 'product_lang';
        $manufacturerTable = $prefix . 'manufacturer';
        $finalTable = 'tm2dn_dashboard.competitor_url_final';
        $testResultTable = 'tm2dn_dashboard.competitor_url_test_result';

        $joins = [
            sprintf('LEFT JOIN %s pl ON pl.id_product = p.id_product AND pl.id_lang = :lang_id AND pl.id_shop = :shop_id', $productLangTable),
            sprintf('LEFT JOIN %s m ON m.id_manufacturer = p.id_manufacturer', $manufacturerTable),
            sprintf('LEFT JOIN %s f ON f.id = p.id_product AND f.competitor_id = :competitor_id', $finalTable),
            sprintf('LEFT JOIN %s tr ON tr.id_product = p.id_product AND tr.competitor_id = :competitor_id', $testResultTable),
        ];

        $referenceExpression = 'p.reference';

        $sql = sprintf(
            'SELECT p.id_product,
                    NULLIF(TRIM(COALESCE(%s, \'\')), \'\') AS supplier_reference,
                    NULLIF(TRIM(COALESCE(p.ean13, \'\')), \'\') AS ean,
                    NULLIF(TRIM(COALESCE(m.name, \'\')), \'\') AS brand,
                    COALESCE(NULLIF(TRIM(COALESCE(pl.name, \'\')), \'\'), CONCAT(\'Product \', p.id_product)) AS name
             FROM %s p
             %s
             WHERE p.id_product > :after_id
               AND f.id IS NULL
               AND p.active = 1
               AND p.visibility IN (\'both\', \'catalog\', \'search\')
               AND NULLIF(TRIM(COALESCE(p.reference, \'\')), \'\') IS NOT NULL
               AND (tr.result IS NULL OR tr.result NOT IN (\'not_found\', \'cloudflare\', \'search_input_not_found\'))
               AND LOWER(COALESCE(p.reference, \'\')) NOT LIKE \'%%b-%%\'
               AND LOWER(COALESCE(p.reference, \'\')) NOT LIKE \'%%occas%%\'
               AND LOWER(COALESCE(p.reference, \'\')) NOT LIKE \'%%depv%%\'
             ORDER BY p.id_product ASC
             LIMIT :limit',
            $referenceExpression,
            $productTable,
            implode("\n             ", $joins)
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

    private function resolvePrefix(): string
    {
        if (isset($this->prefixCache['prefix'])) {
            return $this->prefixCache['prefix'];
        }

        $tableNames = $this->prestashopConnection->fetchFirstColumn('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()');
        $tables = array_map('strval', $tableNames);

        $prefix = 'ps_';
        foreach ($tables as $table) {
            if (str_ends_with($table, 'product')) {
                $candidate = substr($table, 0, -strlen('product'));
                if (in_array($candidate . 'product_lang', $tables, true)) {
                    $prefix = $candidate;
                    break;
                }
            }
        }

        $this->prefixCache['prefix'] = $prefix;

        return $prefix;
    }

}
