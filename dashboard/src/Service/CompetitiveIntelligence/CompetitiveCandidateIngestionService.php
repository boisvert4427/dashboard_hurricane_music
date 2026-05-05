<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use App\Entity\Competitor;
use App\Entity\CompetitorUrlCandidate;
use App\Entity\CompetitorUrlFinal;
use Doctrine\ORM\EntityManagerInterface;

final class CompetitiveCandidateIngestionService
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

        $results = $payload['results'] ?? [];
        if (!is_array($results)) {
            throw new \RuntimeException('results must be an array.');
        }

        $inserted = 0;
        $updated = 0;
        $ignored = 0;
        $candidateRepository = $this->entityManager->getRepository(CompetitorUrlCandidate::class);
        $finalRepository = $this->entityManager->getRepository(CompetitorUrlFinal::class);
        $bestFinalByProductId = [];

        foreach ($results as $result) {
            if (!is_array($result)) {
                $ignored++;
                continue;
            }

            $productId = (int) ($result['id_product'] ?? 0);
            $url = trim((string) ($result['url'] ?? ''));
            if ($productId <= 0 || $url === '') {
                $ignored++;
                continue;
            }

            $existing = $candidateRepository->findOneBy([
                'productId' => $productId,
                'competitor' => $competitor,
                'url' => $url,
            ]);

            if ($existing instanceof CompetitorUrlCandidate) {
                $existing
                    ->setTitle($this->truncateNullableString($result['title'] ?? null, 255))
                    ->setSource($this->truncateNullableString($result['source'] ?? null, 50))
                    ->setScore((int) ($result['score'] ?? 0))
                    ->setStatus($this->normalizeStatus((string) ($result['status'] ?? CompetitorUrlCandidate::STATUS_PENDING)));
                $this->rememberFinalCandidate(
                    $bestFinalByProductId,
                    $productId,
                    $url,
                    (int) ($result['score'] ?? 0),
                    (string) ($result['status'] ?? CompetitorUrlCandidate::STATUS_PENDING),
                );
                $updated++;
                continue;
            }

            $candidate = new CompetitorUrlCandidate(
                $productId,
                $competitor,
                $url,
                $this->truncateNullableString($result['title'] ?? null, 255),
                $this->truncateNullableString($result['source'] ?? null, 50),
                (int) ($result['score'] ?? 0),
                $this->normalizeStatus((string) ($result['status'] ?? CompetitorUrlCandidate::STATUS_PENDING)),
            );

            $this->entityManager->persist($candidate);
            $this->rememberFinalCandidate(
                $bestFinalByProductId,
                $productId,
                $url,
                (int) ($result['score'] ?? 0),
                (string) ($result['status'] ?? CompetitorUrlCandidate::STATUS_PENDING),
            );
            $inserted++;
        }

        foreach ($bestFinalByProductId as $productId => $bestCandidate) {
            $this->upsertFinalIfNeeded(
                $finalRepository,
                (int) $productId,
                $competitor,
                (string) $bestCandidate['url'],
                (int) $bestCandidate['score'],
                (string) $bestCandidate['status'],
            );
        }

        $this->entityManager->flush();

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'ignored' => $ignored,
        ];
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, [
            CompetitorUrlCandidate::STATUS_PENDING,
            CompetitorUrlCandidate::STATUS_VALID,
            CompetitorUrlCandidate::STATUS_REJECTED,
        ], true) ? $status : CompetitorUrlCandidate::STATUS_PENDING;
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

    /**
     * @param array<int, array{url:string, score:int, status:string}> $bestFinalByProductId
     */
    private function rememberFinalCandidate(array &$bestFinalByProductId, int $productId, string $url, int $score, string $status): void
    {
        if ($score <= 90 && $status !== CompetitorUrlCandidate::STATUS_VALID) {
            return;
        }

        if (!isset($bestFinalByProductId[$productId]) || $score >= $bestFinalByProductId[$productId]['score']) {
            $bestFinalByProductId[$productId] = [
                'url' => $url,
                'score' => $score,
                'status' => $status,
            ];
        }
    }

    /**
     * @param object $finalRepository
     */
    private function upsertFinalIfNeeded(object $finalRepository, int $productId, Competitor $competitor, string $url, int $score, string $status): void
    {
        if ($score <= 90 && $status !== CompetitorUrlCandidate::STATUS_VALID) {
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
