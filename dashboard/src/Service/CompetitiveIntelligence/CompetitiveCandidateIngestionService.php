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
                    ->setTitle($this->nullableString($result['title'] ?? null))
                    ->setSource($this->nullableString($result['source'] ?? null))
                    ->setScore((int) ($result['score'] ?? 0))
                    ->setStatus($this->normalizeStatus((string) ($result['status'] ?? CompetitorUrlCandidate::STATUS_PENDING)));
                $this->upsertFinalIfNeeded($finalRepository, $productId, $competitor, $url, (int) ($result['score'] ?? 0), (string) ($result['status'] ?? CompetitorUrlCandidate::STATUS_PENDING));
                $updated++;
                continue;
            }

            $candidate = new CompetitorUrlCandidate(
                $productId,
                $competitor,
                $url,
                $this->nullableString($result['title'] ?? null),
                $this->nullableString($result['source'] ?? null),
                (int) ($result['score'] ?? 0),
                $this->normalizeStatus((string) ($result['status'] ?? CompetitorUrlCandidate::STATUS_PENDING)),
            );

            $this->entityManager->persist($candidate);
            $this->upsertFinalIfNeeded($finalRepository, $productId, $competitor, $url, (int) ($result['score'] ?? 0), (string) ($result['status'] ?? CompetitorUrlCandidate::STATUS_PENDING));
            $inserted++;
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

    /**
     * @param object $finalRepository
     */
    private function upsertFinalIfNeeded(object $finalRepository, int $productId, Competitor $competitor, string $url, int $score, string $status): void
    {
        if ($score < 100 && $status !== CompetitorUrlCandidate::STATUS_VALID) {
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
