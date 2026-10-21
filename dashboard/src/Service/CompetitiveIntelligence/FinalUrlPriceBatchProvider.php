<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class FinalUrlPriceBatchProvider
{
    public function __construct(
        private readonly Connection $databaseConnection,
        private readonly ?Connection $prestashopConnection = null,
    ) {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, after_id: int, limit: int, competitor_id: int, has_more: bool}
     */
    public function getNextBatch(int $competitorId, int $limit = 50, int $afterId = 0, int $productId = 0): array
    {
        $limit = max(1, min(200, $limit));

        $rows = $this->fetchEligibleBatchSegment($competitorId, $afterId, $limit, false, $productId);
        if (count($rows) < $limit) {
            $rows = array_merge(
                $rows,
                $this->fetchEligibleBatchSegment($competitorId, $afterId, $limit - count($rows), true, $productId)
            );
        }

        $items = array_map(
            static function (array $row) use ($competitorId): array {
                return [
                    'id_product' => (int) ($row['id_product'] ?? 0),
                    'competitor_id' => $competitorId,
                    'competitor_name' => trim((string) ($row['competitor_name'] ?? '')),
                    'competitor_domain' => trim((string) ($row['competitor_domain'] ?? '')),
                    'url' => trim((string) ($row['url'] ?? '')),
                    'source_price' => isset($row['source_price']) ? (float) $row['source_price'] : null,
                    'last_scraped_at' => $row['last_scraped_at'] ?? null,
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchBatchSegment(int $competitorId, int $afterId, int $limit, bool $wrapAround, int $productId = 0): array
    {
        return $this->fetchBatchSegmentExcluding($competitorId, $afterId, $limit, $wrapAround, $productId, []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchEligibleBatchSegment(int $competitorId, int $afterId, int $limit, bool $wrapAround, int $productId = 0): array
    {
        if ($this->prestashopConnection === null) {
            return $this->fetchBatchSegment($competitorId, $afterId, $limit, $wrapAround, $productId);
        }

        $eligibleRows = [];
        $excludedIds = [];

        while (count($eligibleRows) < $limit) {
            $candidates = $this->fetchBatchSegmentExcluding(
                $competitorId,
                $afterId,
                max(50, $limit - count($eligibleRows)),
                $wrapAround,
                $productId,
                $excludedIds,
            );
            if ($candidates === []) {
                break;
            }

            $candidateIds = array_map(static fn (array $row): int => (int) $row['id_product'], $candidates);
            $eligibleIds = $this->getEligibleProductIds($candidateIds);
            foreach ($candidates as $candidate) {
                $id = (int) $candidate['id_product'];
                $excludedIds[] = $id;
                if (isset($eligibleIds[$id])) {
                    $eligibleRows[] = $candidate;
                    if (count($eligibleRows) >= $limit) {
                        break;
                    }
                }
            }

            if (count($eligibleRows) >= $limit) {
                break;
            }
        }

        return $eligibleRows;
    }

    /**
     * @param list<int> $excludedIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchBatchSegmentExcluding(
        int $competitorId,
        int $afterId,
        int $limit,
        bool $wrapAround,
        int $productId,
        array $excludedIds,
    ): array
    {
        if ($limit <= 0) {
            return [];
        }

        $operator = $wrapAround ? '<=' : '>';

        $exclusionSql = $excludedIds === [] ? '' : ' AND f.id NOT IN (:excluded_ids)';

        return $this->databaseConnection->fetchAllAssociative(
            'SELECT f.id AS id_product,
                    f.competitor_id,
                    c.name AS competitor_name,
                    c.domain AS competitor_domain,
                    f.url,
                    f.competitor_price AS source_price,
                    f.last_price_attempt_at AS last_scraped_at
             FROM competitor_url_final f
             INNER JOIN competitor c ON c.id = f.competitor_id
             WHERE f.competitor_id = :competitor_id
               AND (:competitor_id <> 1 OR f.url LIKE :woodbrass_product_pattern)
               AND (:competitor_id <> 1 OR f.url NOT LIKE :woodbrass_json_pattern)
               AND (f.next_price_check_at IS NULL OR f.next_price_check_at <= :now)
               AND (:product_id = 0 OR f.id = :product_id)
               AND f.id ' . $operator . ' :after_id
               ' . $exclusionSql . '
             ORDER BY (f.price_check_requested_at IS NULL) ASC,
                      f.price_check_requested_at ASC,
                      f.last_price_attempt_at ASC,
                      f.id ASC
             LIMIT :limit',
            [
                'competitor_id' => $competitorId,
                'woodbrass_product_pattern' => '%/products/%',
                'woodbrass_json_pattern' => '%.js',
                'after_id' => $afterId,
                'product_id' => $productId,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'limit' => $limit,
                ...($excludedIds === [] ? [] : ['excluded_ids' => $excludedIds]),
            ],
            [
                'competitor_id' => ParameterType::INTEGER,
                'after_id' => ParameterType::INTEGER,
                'product_id' => ParameterType::INTEGER,
                'limit' => ParameterType::INTEGER,
                ...($excludedIds === [] ? [] : ['excluded_ids' => ArrayParameterType::INTEGER]),
            ]
        );
    }

    public function hasPendingWork(int $competitorId, int $afterId = 0): bool
    {
        if ($this->prestashopConnection === null) {
            return (bool) $this->databaseConnection->fetchOne(
                'SELECT EXISTS(
                    SELECT 1
                    FROM competitor_url_final f
                    WHERE f.competitor_id = :competitor_id
                      AND (:competitor_id <> 1 OR f.url LIKE :woodbrass_product_pattern)
                      AND (:competitor_id <> 1 OR f.url NOT LIKE :woodbrass_json_pattern)
                      AND (f.next_price_check_at IS NULL OR f.next_price_check_at <= :now)
                )',
                [
                    'competitor_id' => $competitorId,
                    'woodbrass_product_pattern' => '%/products/%',
                    'woodbrass_json_pattern' => '%.js',
                    'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
                [
                    'competitor_id' => ParameterType::INTEGER,
                ]
            );
        }

        $ids = $this->databaseConnection->fetchFirstColumn(
            'SELECT f.id
             FROM competitor_url_final f
             WHERE f.competitor_id = :competitor_id
               AND (:competitor_id <> 1 OR f.url LIKE :woodbrass_product_pattern)
               AND (:competitor_id <> 1 OR f.url NOT LIKE :woodbrass_json_pattern)
               AND (f.next_price_check_at IS NULL OR f.next_price_check_at <= :now)',
            [
                'competitor_id' => $competitorId,
                'woodbrass_product_pattern' => '%/products/%',
                'woodbrass_json_pattern' => '%.js',
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            ['competitor_id' => ParameterType::INTEGER]
        );

        foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
            if ($this->getEligibleProductIds($chunk) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $productIds
     * @return array<int, true>
     */
    private function getEligibleProductIds(array $productIds): array
    {
        if ($productIds === [] || $this->prestashopConnection === null) {
            return [];
        }

        $ids = $this->prestashopConnection->fetchFirstColumn(
            'SELECT id_product FROM leo_netrivals_send_feed WHERE id_product IN (:ids)',
            ['ids' => $productIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return array_fill_keys(array_map('intval', $ids), true);
    }
}
