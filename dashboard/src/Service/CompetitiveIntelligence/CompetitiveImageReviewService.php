<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use App\Entity\CompetitorUrlFinal;
use App\Entity\CompetitorUrlTestResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CompetitiveImageReviewService
{
    private const MAX_IMAGE_DIMENSION = 768;
    private const JPEG_QUALITY = 82;
    private const OPENAI_BATCH_SIZE = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PrestashopProductBatchProvider $batchProvider,
        private readonly CompetitiveTestResultReviewService $reviewService,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return array{processed:int, valid:int, rejected:int, postponed:int, missing_images:int, errors:int}
     */
    public function reviewAllPending(int $limit = 5): array
    {
        return $this->reviewPendingBatch($limit)['stats'];
    }

    /**
     * @return array{
     *     stats:array{processed:int, valid:int, rejected:int, postponed:int, missing_images:int, errors:int},
     *     items:array<int, array<string, mixed>>
     * }
     */
    public function reviewPendingBatch(int $limit = 5): array
    {
        $pendingRows = $this->getPendingRows($limit);
        if ($pendingRows === []) {
            return [
                'stats' => [
                    'processed' => 0,
                    'valid' => 0,
                    'rejected' => 0,
                    'postponed' => 0,
                    'missing_images' => 0,
                    'errors' => 0,
                ],
                'items' => [],
            ];
        }

        $productIds = array_map(static fn (CompetitorUrlTestResult $row): int => $row->getProductId(), $pendingRows);
        $sourceSnapshots = $this->batchProvider->getProductSnapshotsByIds($productIds);
        $stats = [
            'processed' => 0,
            'valid' => 0,
            'rejected' => 0,
            'postponed' => 0,
            'missing_images' => 0,
            'errors' => 0,
        ];
        $items = [];
        $batchQueue = [];

        foreach ($pendingRows as $row) {
            $stats['processed']++;

            $productId = $row->getProductId();
            $sourceSnapshot = $sourceSnapshots[$productId] ?? null;
            $sourceImageUrl = is_array($sourceSnapshot) ? ($sourceSnapshot['source_image_url'] ?? null) : null;
            $competitorImageUrl = $row->getCompetitorImageUrl();

            if (!$this->hasUsableImage($sourceImageUrl) || !$this->hasUsableImage($competitorImageUrl)) {
                $this->reviewService->updateReviewStatus($productId, $row->getCompetitor()->getId() ?? 0, CompetitorUrlTestResult::REVIEW_POSTPONED, false);
                $stats['postponed']++;
                $stats['missing_images']++;
                $items[] = [
                    'product_id' => $productId,
                    'competitor_id' => $row->getCompetitor()->getId(),
                    'competitor_name' => $row->getCompetitor()->getName(),
                    'url' => $row->getUrl(),
                    'status' => CompetitorUrlTestResult::REVIEW_POSTPONED,
                    'reason' => 'missing_images',
                    'source_image_url' => $sourceImageUrl,
                    'competitor_image_url' => $competitorImageUrl,
                ];
                continue;
            }

            $batchQueue[] = [
                'key' => $this->comparisonKey($productId, $row->getCompetitor()->getId() ?? 0),
                'product_id' => $productId,
                'competitor_id' => $row->getCompetitor()->getId() ?? 0,
                'competitor_name' => $row->getCompetitor()->getName(),
                'url' => $row->getUrl(),
                'source_image_url' => $sourceImageUrl,
                'competitor_image_url' => $competitorImageUrl,
                'source_context' => is_array($sourceSnapshot) ? [
                    'name' => $sourceSnapshot['name'] ?? null,
                    'brand' => $sourceSnapshot['brand'] ?? null,
                    'category_path' => $sourceSnapshot['category_path'] ?? null,
                    'category' => $sourceSnapshot['category'] ?? null,
                    'supplier_reference' => $sourceSnapshot['supplier_reference'] ?? null,
                    'ean' => $sourceSnapshot['ean'] ?? null,
                    'source_price' => $sourceSnapshot['source_price'] ?? null,
                    'image_url' => $sourceImageUrl,
                ] : [
                    'name' => null,
                    'brand' => null,
                    'category_path' => null,
                    'category' => null,
                    'supplier_reference' => null,
                    'ean' => null,
                    'source_price' => null,
                    'image_url' => $sourceImageUrl,
                ],
                'competitor_context' => [
                    'name' => $row->getCompetitor()->getName(),
                    'title' => $row->getCompetitorTitle(),
                    'brand' => $row->getCompetitorBrand(),
                    'breadcrumb' => $row->getCompetitorBreadcrumb(),
                    'price' => $row->getCompetitorPrice(),
                    'url' => $row->getUrl(),
                    'image_url' => $competitorImageUrl,
                ],
            ];
        }

        foreach (array_chunk($batchQueue, self::OPENAI_BATCH_SIZE) as $chunk) {
            $preparedChunk = [];
            foreach ($chunk as $item) {
                try {
                    $preparedChunk[] = [
                        'key' => (string) $item['key'],
                        'product_id' => (int) $item['product_id'],
                        'competitor_id' => (int) $item['competitor_id'],
                        'competitor_name' => (string) $item['competitor_name'],
                        'url' => (string) $item['url'],
                        'source_image_url' => (string) $item['source_image_url'],
                        'competitor_image_url' => (string) $item['competitor_image_url'],
                        'source_image_data_uri' => $this->downloadImageAsDataUri((string) $item['source_image_url']),
                        'competitor_image_data_uri' => $this->downloadImageAsDataUri((string) $item['competitor_image_url']),
                        'source_context' => $item['source_context'],
                        'competitor_context' => $item['competitor_context'],
                    ];
                } catch (\Throwable $throwable) {
                    $stats['errors']++;
                    $stats['postponed']++;
                    $this->reviewService->updateReviewStatus((int) $item['product_id'], (int) $item['competitor_id'], CompetitorUrlTestResult::REVIEW_POSTPONED, false);
                    $items[] = [
                        'product_id' => (int) $item['product_id'],
                        'competitor_id' => (int) $item['competitor_id'],
                        'competitor_name' => (string) $item['competitor_name'],
                        'url' => (string) $item['url'],
                        'status' => CompetitorUrlTestResult::REVIEW_POSTPONED,
                        'comparison' => null,
                        'error' => $throwable->getMessage(),
                        'source_image_url' => (string) $item['source_image_url'],
                        'competitor_image_url' => (string) $item['competitor_image_url'],
                    ];
                }
            }

            if ($preparedChunk === []) {
                continue;
            }

            try {
                $comparisons = $this->compareImageBatch($preparedChunk);
            } catch (\Throwable $throwable) {
                foreach ($preparedChunk as $item) {
                    $stats['errors']++;
                    $stats['postponed']++;
                    $this->reviewService->updateReviewStatus((int) $item['product_id'], (int) $item['competitor_id'], CompetitorUrlTestResult::REVIEW_POSTPONED, false);
                    $items[] = [
                        'product_id' => (int) $item['product_id'],
                        'competitor_id' => (int) $item['competitor_id'],
                        'competitor_name' => (string) $item['competitor_name'],
                        'url' => (string) $item['url'],
                        'status' => CompetitorUrlTestResult::REVIEW_POSTPONED,
                        'comparison' => null,
                        'error' => $throwable->getMessage(),
                        'source_image_url' => (string) $item['source_image_url'],
                        'competitor_image_url' => (string) $item['competitor_image_url'],
                    ];
                }
                continue;
            }

            foreach ($preparedChunk as $item) {
                $comparison = $comparisons[$item['key']] ?? null;
                if (!is_array($comparison)) {
                    $stats['errors']++;
                    $stats['postponed']++;
                    $this->reviewService->updateReviewStatus((int) $item['product_id'], (int) $item['competitor_id'], CompetitorUrlTestResult::REVIEW_POSTPONED, false);
                    $items[] = [
                        'product_id' => (int) $item['product_id'],
                        'competitor_id' => (int) $item['competitor_id'],
                        'competitor_name' => (string) $item['competitor_name'],
                        'url' => (string) $item['url'],
                        'status' => CompetitorUrlTestResult::REVIEW_POSTPONED,
                        'comparison' => null,
                        'error' => sprintf('Missing comparison for key "%s".', (string) $item['key']),
                        'source_image_url' => (string) $item['source_image_url'],
                        'competitor_image_url' => (string) $item['competitor_image_url'],
                    ];
                    continue;
                }

                $status = $this->resolveStatus($comparison);
                $error = null;

                if ($status === CompetitorUrlTestResult::REVIEW_VALID) {
                    $stats['valid']++;
                } elseif ($status === CompetitorUrlTestResult::REVIEW_REJECTED) {
                    $stats['rejected']++;
                } else {
                    $stats['postponed']++;
                }

                $this->reviewService->updateReviewStatus((int) $item['product_id'], (int) $item['competitor_id'], $status, false);
                $items[] = [
                    'product_id' => (int) $item['product_id'],
                    'competitor_id' => (int) $item['competitor_id'],
                    'competitor_name' => (string) $item['competitor_name'],
                    'url' => (string) $item['url'],
                    'status' => $status,
                    'comparison' => $comparison,
                    'error' => $error,
                    'source_image_url' => (string) $item['source_image_url'],
                    'competitor_image_url' => (string) $item['competitor_image_url'],
                ];
            }

            $this->entityManager->flush();
        }

        return [
            'stats' => $stats,
            'items' => $items,
        ];
    }

    /**
     * @return array<int, CompetitorUrlTestResult>
     */
    private function getPendingRows(int $limit = 5): array
    {
        $rows = $this->entityManager->getRepository(CompetitorUrlTestResult::class)
            ->createQueryBuilder('t')
            ->leftJoin('t.competitor', 'competitor')
            ->leftJoin(
                CompetitorUrlFinal::class,
                'final_row',
                'WITH',
                'final_row.id = t.productId AND final_row.competitor = t.competitor'
            )
            ->addSelect('competitor')
            ->andWhere('t.validationStatus = :status')
            ->andWhere('final_row.id IS NULL')
            ->setParameter('status', CompetitorUrlTestResult::REVIEW_PENDING)
            ->orderBy('t.score', 'DESC')
            ->addOrderBy('t.lastTestedAt', 'DESC')
            ->setMaxResults(max(1, min(50, $limit)))
            ->getQuery()
            ->getResult();

        return array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof CompetitorUrlTestResult));
    }

    private function hasUsableImage(?string $url): bool
    {
        return trim((string) $url) !== '';
    }

    private function comparisonKey(int $productId, int $competitorId): string
    {
        return $productId . ':' . $competitorId;
    }

    /**
     * @param array<int, array{
     *     key:string,
     *     product_id:int,
     *     competitor_id:int,
     *     competitor_name:string,
     *     url:string,
     *     source_image_url:string,
     *     competitor_image_url:string,
     *     source_image_data_uri:string,
     *     competitor_image_data_uri:string,
     *     source_context:array{name:?string,brand:?string,category_path:?string,category:?string,supplier_reference:?string,ean:?string,source_price:?float|int|string|null,image_url:?string},
     *     competitor_context:array{name:?string,title:?string,brand:?string,breadcrumb:?string,price:?string,url:?string,image_url:?string}
     * }> $items
     *
     * @return array<string, array{same_product:bool,confidence:int,notes:array<int,string>}>
     */
    private function compareImageBatch(array $items): array
    {
        $apiKey = trim((string) ($_ENV['OPENAI_API_KEY'] ?? $_SERVER['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: ''));
        if ($apiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY is missing.');
        }

        $model = trim((string) (
            $_ENV['CI_OPENAI_IMAGE_MODEL']
            ?? $_SERVER['CI_OPENAI_IMAGE_MODEL']
            ?? getenv('CI_OPENAI_IMAGE_MODEL')
            ?: $_ENV['CI_OPENAI_MATCH_MODEL']
            ?? $_SERVER['CI_OPENAI_MATCH_MODEL']
            ?? getenv('CI_OPENAI_MATCH_MODEL')
            ?: 'gpt-4.1-mini'
        ));

        $content = [
            [
                'type' => 'text',
                'text' => "Compare each item independently and return strict JSON only.\n\nDecision rules:\n- Only return same_product=true when the two listings are the exact same product variant, not just the same family or a visually similar model.\n- If the model name, SKU, finish, color, scale/size, handedness, category, or other variant-defining detail differs, return same_product=false even if the photos look close.\n- When metadata conflicts with the image, prefer the image for identifying the exact variant, but do not ignore a clear variant mismatch.\n- Return one result per item and preserve the input order by the item key.\n\nItems:\n" . json_encode(array_map(static function (array $item): array {
                    return [
                        'key' => $item['key'],
                        'product_id' => $item['product_id'],
                        'competitor_id' => $item['competitor_id'],
                        'competitor_name' => $item['competitor_name'],
                        'url' => $item['url'],
                        'source' => $item['source_context'],
                        'competitor' => $item['competitor_context'],
                    ];
                }, $items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
        ];

        foreach ($items as $item) {
            $content[] = [
                'type' => 'text',
                'text' => sprintf(
                    "Item key=%s\nSource metadata:\n%s\nCompetitor metadata:\n%s",
                    $item['key'],
                    json_encode($item['source_context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    json_encode($item['competitor_context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                ),
            ];
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $item['source_image_data_uri'],
                    'detail' => 'low',
                ],
            ];
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $item['competitor_image_data_uri'],
                    'detail' => 'low',
                ],
            ];
        }

        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You compare music product photos in batches. Return strict JSON only, no markdown, no prose.',
                ],
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'image_review_batch',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['comparisons'],
                        'properties' => [
                            'comparisons' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['key', 'same_product', 'confidence', 'notes'],
                                    'properties' => [
                                        'key' => ['type' => 'string'],
                                        'same_product' => ['type' => 'boolean'],
                                        'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                                        'notes' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
            'timeout' => 120,
        ]);

        $data = $response->toArray(false);
        $content = $data['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {
            $content = json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $parsed = json_decode((string) $content, true);
        if (!is_array($parsed)) {
            throw new \RuntimeException('Invalid OpenAI image comparison response: ' . substr((string) $content, 0, 500));
        }

        $comparisons = $parsed['comparisons'] ?? null;
        if (!is_array($comparisons)) {
            throw new \RuntimeException('Invalid OpenAI image comparison response: missing comparisons array.');
        }

        $results = [];
        foreach ($comparisons as $comparison) {
            if (!is_array($comparison)) {
                continue;
            }

            $key = trim((string) ($comparison['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $results[$key] = [
                'same_product' => (bool) ($comparison['same_product'] ?? false),
                'confidence' => max(0, min(100, (int) ($comparison['confidence'] ?? 0))),
                'notes' => array_values(array_filter(array_map('strval', $comparison['notes'] ?? []))),
            ];
        }

        return $results;
    }

    private function downloadImageAsDataUri(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new \RuntimeException('Image URL is empty.');
        }

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'image/*,*/*;q=0.8',
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('Failed to download image (%d) %s', $status, $url));
        }

        $contentType = $response->getHeaders(false)['content-type'][0] ?? 'image/jpeg';
        $content = $response->getContent();
        if ($content === '') {
            throw new \RuntimeException(sprintf('Empty image content for %s', $url));
        }

        $optimized = $this->optimizeImageForReview($content, $contentType);

        return sprintf('data:%s;base64,%s', $optimized['content_type'], base64_encode($optimized['content']));
    }

    /**
     * @return array{content:string, content_type:string}
     */
    private function optimizeImageForReview(string $content, string $contentType): array
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
            return [
                'content' => $content,
                'content_type' => $contentType,
            ];
        }

        $image = @imagecreatefromstring($content);
        if ($image === false) {
            return [
                'content' => $content,
                'content_type' => $contentType,
            ];
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($image);

            return [
                'content' => $content,
                'content_type' => $contentType,
            ];
        }

        $maxDimension = self::MAX_IMAGE_DIMENSION;
        $largestSide = max($width, $height);
        if ($largestSide <= $maxDimension) {
            imagedestroy($image);

            return [
                'content' => $content,
                'content_type' => $contentType,
            ];
        }

        $scale = $maxDimension / $largestSide;
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($resized === false) {
            imagedestroy($image);

            return [
                'content' => $content,
                'content_type' => $contentType,
            ];
        }

        $background = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $background);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($resized, null, self::JPEG_QUALITY);
        $optimized = ob_get_clean();

        imagedestroy($image);
        imagedestroy($resized);

        if ($optimized === false || $optimized === '') {
            return [
                'content' => $content,
                'content_type' => $contentType,
            ];
        }

        return [
            'content' => $optimized,
            'content_type' => 'image/jpeg',
        ];
    }

    /**
     * @param array{same_product:bool,confidence:int,notes:array<int,string>} $comparison
     */
    private function resolveStatus(array $comparison): string
    {
        $confidence = (int) ($comparison['confidence'] ?? 0);
        if ($confidence < 70) {
            return CompetitorUrlTestResult::REVIEW_POSTPONED;
        }

        return !empty($comparison['same_product'])
            ? CompetitorUrlTestResult::REVIEW_VALID
            : CompetitorUrlTestResult::REVIEW_REJECTED;
    }
}
