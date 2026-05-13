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
    public function getNextBatch(int $competitorId, int $limit = 50, int $afterId = 0): array
    {
        $limit = max(1, min(200, $limit));

        $rows = $this->databaseConnection->fetchAllAssociative(
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
               AND f.id > :after_id
             ORDER BY (last_price.last_scraped_at IS NOT NULL) ASC,
                      last_price.last_scraped_at ASC,
                      f.id ASC
             LIMIT :limit',
            [
                'competitor_id' => $competitorId,
                'after_id' => $afterId,
                'limit' => $limit,
            ],
            [
                'competitor_id' => ParameterType::INTEGER,
                'after_id' => ParameterType::INTEGER,
                'limit' => ParameterType::INTEGER,
            ]
        );

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

    public function hasPendingWork(int $competitorId, int $afterId = 0): bool
    {
        return (bool) $this->databaseConnection->fetchOne(
            'SELECT EXISTS(
                SELECT 1
                FROM competitor_url_final f
                WHERE f.competitor_id = :competitor_id
                  AND f.id > :after_id
            )',
            [
                'competitor_id' => $competitorId,
                'after_id' => $afterId,
            ],
            [
                'competitor_id' => ParameterType::INTEGER,
                'after_id' => ParameterType::INTEGER,
            ]
        );
    }
}
