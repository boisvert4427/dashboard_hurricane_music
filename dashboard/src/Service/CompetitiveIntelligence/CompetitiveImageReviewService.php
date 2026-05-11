<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use App\Entity\CompetitorUrlFinal;
use App\Entity\CompetitorUrlTestResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CompetitiveImageReviewService
{
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
    public function reviewAllPending(): array
    {
        $pendingRows = $this->getPendingRows();
        if ($pendingRows === []) {
            return [
                'processed' => 0,
                'valid' => 0,
                'rejected' => 0,
                'postponed' => 0,
                'missing_images' => 0,
                'errors' => 0,
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
                continue;
            }

            try {
                $comparison = $this->compareImages(
                    (string) $sourceImageUrl,
                    (string) $competitorImageUrl,
                    [
                        'source' => is_array($sourceSnapshot) ? [
                            'name' => $sourceSnapshot['name'] ?? null,
                            'brand' => $sourceSnapshot['brand'] ?? null,
                            'supplier_reference' => $sourceSnapshot['supplier_reference'] ?? null,
                            'ean' => $sourceSnapshot['ean'] ?? null,
                            'source_price' => $sourceSnapshot['source_price'] ?? null,
                            'image_url' => $sourceImageUrl,
                        ] : [
                            'name' => null,
                            'brand' => null,
                            'supplier_reference' => null,
                            'ean' => null,
                            'source_price' => null,
                            'image_url' => $sourceImageUrl,
                        ],
                        'competitor' => [
                            'name' => $row->getCompetitor()->getName(),
                            'title' => $row->getCompetitorTitle(),
                            'brand' => $row->getCompetitorBrand(),
                            'breadcrumb' => $row->getCompetitorBreadcrumb(),
                            'price' => $row->getCompetitorPrice(),
                            'url' => $row->getUrl(),
                            'image_url' => $competitorImageUrl,
                        ],
                    ]
                );
                $status = $this->resolveStatus($comparison);
            } catch (\Throwable) {
                $status = CompetitorUrlTestResult::REVIEW_POSTPONED;
                $stats['errors']++;
            }

            if ($status === CompetitorUrlTestResult::REVIEW_VALID) {
                $stats['valid']++;
            } elseif ($status === CompetitorUrlTestResult::REVIEW_REJECTED) {
                $stats['rejected']++;
            } else {
                $stats['postponed']++;
            }

            $this->reviewService->updateReviewStatus($productId, $row->getCompetitor()->getId() ?? 0, $status, false);
        }

        $this->entityManager->flush();

        return $stats;
    }

    /**
     * @return array<int, CompetitorUrlTestResult>
     */
    private function getPendingRows(): array
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
            ->getQuery()
            ->getResult();

        return array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof CompetitorUrlTestResult));
    }

    private function hasUsableImage(?string $url): bool
    {
        return trim((string) $url) !== '';
    }

    /**
     * @param array{
     *     source:array{name:?string,brand:?string,supplier_reference:?string,ean:?string,source_price:?float|int|string|null,image_url:?string},
     *     competitor:array{name:?string,title:?string,brand:?string,breadcrumb:?string,price:?string,image_url:?string,url:?string}
     * } $context
     *
     * @return array{same_product:bool,confidence:int,notes:array<int,string>}
     */
    private function compareImages(string $sourceImageUrl, string $competitorImageUrl, array $context): array
    {
        $apiKey = trim((string) getenv('OPENAI_API_KEY'));
        if ($apiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY is missing.');
        }

        $model = trim((string) (getenv('CI_OPENAI_IMAGE_MODEL') ?: getenv('CI_OPENAI_MATCH_MODEL') ?: 'gpt-4.1-mini'));
        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You compare music product photos. Return strict JSON only, no markdown, no prose.',
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => "Compare the two product images and use the metadata as context.\nIf metadata conflicts with the image, trust the image.\nReturn strict JSON only.\n\nMetadata:\n" . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $sourceImageUrl,
                                'detail' => 'low',
                            ],
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $competitorImageUrl,
                                'detail' => 'low',
                            ],
                        ],
                    ],
                ],
            ],
            'temperature' => 0,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'image_review',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['same_product', 'confidence', 'notes'],
                        'properties' => [
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
        $parsed = json_decode((string) $content, true);
        if (!is_array($parsed)) {
            throw new \RuntimeException('Invalid OpenAI image comparison response.');
        }

        return [
            'same_product' => (bool) ($parsed['same_product'] ?? false),
            'confidence' => max(0, min(100, (int) ($parsed['confidence'] ?? 0))),
            'notes' => array_values(array_filter(array_map('strval', $parsed['notes'] ?? []))),
        ];
    }

    /**
     * @param array{same_product:bool,confidence:int,notes:array<int,string>} $comparison
     */
    private function resolveStatus(array $comparison): string
    {
        $confidence = (int) ($comparison['confidence'] ?? 0);
        if ($confidence < 75) {
            return CompetitorUrlTestResult::REVIEW_POSTPONED;
        }

        return !empty($comparison['same_product'])
            ? CompetitorUrlTestResult::REVIEW_VALID
            : CompetitorUrlTestResult::REVIEW_REJECTED;
    }
}
