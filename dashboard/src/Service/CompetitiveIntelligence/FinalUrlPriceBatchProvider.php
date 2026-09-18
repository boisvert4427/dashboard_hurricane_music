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
    ) {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, after_id: int, limit: int, competitor_id: int, has_more: bool}
     */
    public function getNextBatch(int $competitorId, int $limit = 50, int $afterId = 0, int $productId = 0): array
    {
        $limit = max(1, min(200, $limit));

        $rows = $this->fetchBatchSegment($competitorId, $afterId, $limit, false, $productId);
        if (count($rows) < $limit) {
            $rows = array_merge(
                $rows,
                $this->fetchBatchSegment($competitorId, $afterId, $limit - count($rows), true, $productId)
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
        if ($limit <= 0) {
            return [];
        }

        $operator = $wrapAround ? '<=' : '>';

        return $this->databaseConnection->fetchAllAssociative(
            'SELECT f.id AS id_product,
                    f.competitor_id,
                    c.name AS competitor_name,
                    c.domain AS competitor_domain,
                    f.url,
                    f.competitor_price AS source_price,
                    last_price.last_scraped_at AS last_scraped_at
             FROM competitor_url_final f
             INNER JOIN competitor c ON c.id = f.competitor_id
             LEFT JOIN (
                 SELECT competitor_id, id_product, url, MAX(observed_at) AS last_scraped_at
                 FROM competitor_url_price_history
                 GROUP BY competitor_id, id_product, url
             ) last_price
               ON last_price.competitor_id = f.competitor_id
              AND last_price.id_product = f.id
              AND last_price.url = f.url
             WHERE f.competitor_id = :competitor_id
               AND (f.next_price_check_at IS NULL OR f.next_price_check_at <= :now)
               AND (:product_id = 0 OR f.id = :product_id)
               AND f.id ' . $operator . ' :after_id
             ORDER BY (f.price_check_requested_at IS NULL) ASC,
                      f.price_check_requested_at ASC,
                      COALESCE(f.last_price_attempt_at, last_price.last_scraped_at) ASC,
                      f.id ASC
             LIMIT :limit',
            [
                'competitor_id' => $competitorId,
                'after_id' => $afterId,
                'product_id' => $productId,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'limit' => $limit,
            ],
            [
                'competitor_id' => ParameterType::INTEGER,
                'after_id' => ParameterType::INTEGER,
                'product_id' => ParameterType::INTEGER,
                'limit' => ParameterType::INTEGER,
            ]
        );
    }

    public function hasPendingWork(int $competitorId, int $afterId = 0): bool
    {
        return (bool) $this->databaseConnection->fetchOne(
            'SELECT EXISTS(
                SELECT 1
                FROM competitor_url_final f
                WHERE f.competitor_id = :competitor_id
                  AND (f.next_price_check_at IS NULL OR f.next_price_check_at <= :now)
            )',
            [
                'competitor_id' => $competitorId,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            [
                'competitor_id' => ParameterType::INTEGER,
            ]
        );
    }
}
