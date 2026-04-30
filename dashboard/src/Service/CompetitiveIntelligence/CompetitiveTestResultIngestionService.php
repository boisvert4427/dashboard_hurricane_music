<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use App\Entity\Competitor;
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

            if ($existing instanceof CompetitorUrlTestResult) {
                $existing
                    ->setResult($result)
                    ->setUrl($this->nullableString($test['url'] ?? null))
                    ->setTitle($this->nullableString($test['title'] ?? null))
                    ->setScore(isset($test['score']) ? (int) $test['score'] : null)
                    ->setMatchedQuery($this->nullableString($test['matched_query'] ?? null))
                    ->setMessage($this->nullableString($test['message'] ?? null))
                    ->touch();
                $updated++;
                continue;
            }

            $this->entityManager->persist(new CompetitorUrlTestResult(
                $productId,
                $competitor,
                $result,
                $this->nullableString($test['url'] ?? null),
                $this->nullableString($test['title'] ?? null),
                isset($test['score']) ? (int) $test['score'] : null,
                $this->nullableString($test['matched_query'] ?? null),
                $this->nullableString($test['message'] ?? null),
            ));
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
}
