<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use App\Entity\Competitor;
use App\Entity\CompetitorUrlFinal;
use App\Entity\CompetitorUrlTestResult;
use Doctrine\ORM\EntityManagerInterface;

final class CompetitiveTestResultIngestionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{inserted:int, updated:int, ignored:int}
     */
    public function ingest(array $payload): array
    {
        $competitorId = (int) ($payload['competitor_id'] ?? 0);
        $competitor = $this->entityManager->getRepository(Competitor::class)->find($competitorId);
        if (!$competitor instanceof Competitor) {
            throw new \RuntimeException(sprintf('Unknown competitor_id "%s".', (string) ($payload['competitor_id'] ?? '')));
        }

        $tests = $payload['tests'] ?? [];
        if (!is_array($tests)) {
            throw new \RuntimeException('tests must be an array.');
        }

        $inserted = 0;
        $updated = 0;
        $ignored = 0;
        $repository = $this->entityManager->getRepository(CompetitorUrlTestResult::class);
        $finalRepository = $this->entityManager->getRepository(CompetitorUrlFinal::class);

        foreach ($tests as $test) {
            if (!is_array($test)) {
                $ignored++;
                continue;
            }

            $productId = (int) ($test['id_product'] ?? 0);
            $result = (string) ($test['result'] ?? '');
            if ($productId <= 0 || $result === '') {
                $ignored++;
                continue;
            }

            $existing = $repository->findOneBy([
                'productId' => $productId,
                'competitor' => $competitor,
            ]);

            $score = isset($test['score']) ? (int) $test['score'] : null;
            if ($score !== null && $score < 30) {
                if ($existing instanceof CompetitorUrlTestResult && $existing->getValidationStatus() === CompetitorUrlTestResult::REVIEW_PENDING) {
                    $this->entityManager->remove($existing);
                    $updated++;
                } else {
                    $ignored++;
                }
                continue;
            }

            if ($existing instanceof CompetitorUrlTestResult) {
                $existing
                    ->setResult($result)
                    ->setUrl($this->truncateNullableString($test['url'] ?? null, 2048))
                    ->setCompetitorTitle($this->truncateNullableString($test['competitor_title'] ?? null, 255))
                    ->setScore($score)
                    ->setCompetitorPrice($this->nullableDecimalString($test['competitor_price'] ?? null))
                    ->setValidationStatus($this->resolveValidationStatus($result, $score, $test['url'] ?? null))
                    ->setMatchedQuery($this->truncateNullableString($test['matched_query'] ?? null, 255))
                    ->setMessage($this->truncateNullableString($test['message'] ?? null, 255))
                    ->touch();
                $this->upsertFinalIfNeeded($finalRepository, $competitor, $existing);
                $updated++;
                continue;
            }

            $this->entityManager->persist(new CompetitorUrlTestResult(
                $productId,
                $competitor,
                $result,
                $this->truncateNullableString($test['url'] ?? null, 2048),
                $this->truncateNullableString($test['competitor_title'] ?? null, 255),
                $score,
                $this->nullableDecimalString($test['competitor_price'] ?? null),
                $this->resolveValidationStatus($result, $score, $test['url'] ?? null),
                $this->truncateNullableString($test['matched_query'] ?? null, 255),
                $this->truncateNullableString($test['message'] ?? null, 255),
            ));
            $this->upsertFinalIfNeeded($finalRepository, $competitor, null, $productId, $this->truncateNullableString($test['url'] ?? null, 2048), $score, $result);
            $inserted++;
        }

        $this->entityManager->flush();

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'ignored' => $ignored,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function truncateNullableString(mixed $value, int $maxLength): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, max(1, $maxLength));
    }

    private function nullableDecimalString(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        $value = str_replace(',', '.', $value);
        if (!is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function resolveValidationStatus(string $result, ?int $score, mixed $url = null): string
    {
        if ($result === CompetitorUrlTestResult::RESULT_MATCHED && $score !== null && $score >= 90) {
            return CompetitorUrlTestResult::REVIEW_VALID;
        }

        $url = trim((string) $url);
        if ($url !== '') {
            return CompetitorUrlTestResult::REVIEW_PENDING;
        }

        return CompetitorUrlTestResult::REVIEW_IGNORED;
    }

    /**
     * @param object $finalRepository
     */
    private function upsertFinalIfNeeded(object $finalRepository, Competitor $competitor, ?CompetitorUrlTestResult $testResult = null, int $productId = 0, ?string $url = null, ?int $score = null, ?string $result = null): void
    {
        $productId = $testResult?->getProductId() ?? $productId;
        $url = $testResult?->getUrl() ?? $url;
        $score = $testResult?->getScore() ?? $score;
        $result = $testResult?->getResult() ?? $result;

        if ($productId <= 0 || $url === null || trim($url) === '') {
            return;
        }

        if (($result !== CompetitorUrlTestResult::RESULT_MATCHED) || ($score === null || $score < 90)) {
            return;
        }

        $existing = $finalRepository->findOneBy([
            'id' => $productId,
            'competitor' => $competitor,
        ]);

        if ($existing instanceof CompetitorUrlFinal) {
            if ($existing->getUrl() !== $url) {
                $existing->setUrl($url);
            }
            return;
        }

        $this->entityManager->persist(new CompetitorUrlFinal($productId, $competitor, $url));
    }
}
