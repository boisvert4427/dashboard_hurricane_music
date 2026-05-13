<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use App\Entity\Competitor;
use App\Entity\CompetitorUrlFinal;
use App\Entity\CompetitorUrlPriceHistory;
use App\Entity\CompetitorUrlTestResult;
use Doctrine\ORM\EntityManagerInterface;

final class CompetitiveFinalPriceIngestionService
{
    private const GONE_HTTP_STATUS_THRESHOLD = 3;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{inserted:int, updated:int, ignored:int, failures:int, removed:int, gone:int}
     */
    public function ingest(array $payload): array
    {
        $competitorId = (int) ($payload['competitor_id'] ?? 0);
        $competitor = $this->entityManager->getRepository(Competitor::class)->find($competitorId);
        if (!$competitor instanceof Competitor) {
            throw new \RuntimeException(sprintf('Unknown competitor_id "%s".', (string) ($payload['competitor_id'] ?? '')));
        }

        $observations = $payload['observations'] ?? [];
        if (!is_array($observations)) {
            throw new \RuntimeException('observations must be an array.');
        }

        $inserted = 0;
        $updated = 0;
        $ignored = 0;
        $failures = 0;
        $removed = 0;
        $gone = 0;
        $finalRepository = $this->entityManager->getRepository(CompetitorUrlFinal::class);
        $testResultRepository = $this->entityManager->getRepository(CompetitorUrlTestResult::class);

        foreach ($observations as $observation) {
            if (!is_array($observation)) {
                $ignored++;
                continue;
            }

            $productId = (int) ($observation['id_product'] ?? 0);
            $url = trim((string) ($observation['url'] ?? ''));
            $price = $this->nullableDecimalString($observation['price'] ?? null);
            if ($productId <= 0 || $url === '' || $price === null) {
                $ignored++;
                continue;
            }

            $final = $finalRepository->findOneBy([
                'id' => $productId,
                'competitor' => $competitor,
            ]);
            if (!$final instanceof CompetitorUrlFinal) {
                $ignored++;
                continue;
            }

            if ($final->getUrl() !== $url) {
                $final->setUrl($url);
            }
            $final->setCompetitorPrice($price);
            $final->resetHttpFailureState();

            $this->entityManager->persist(new CompetitorUrlPriceHistory(
                $productId,
                $competitor,
                $url,
                $price,
                (string) ($observation['source'] ?? 'final_price'),
            ));

            $updated++;
        }

        $failureRows = $payload['failures'] ?? [];
        if (is_array($failureRows)) {
            foreach ($failureRows as $failure) {
                if (!is_array($failure)) {
                    $ignored++;
                    continue;
                }

                $productId = (int) ($failure['id_product'] ?? 0);
                $url = trim((string) ($failure['url'] ?? ''));
                $httpStatus = isset($failure['http_status']) ? (int) $failure['http_status'] : null;
                $message = trim((string) ($failure['error'] ?? ''));
                if ($productId <= 0 || $url === '' || $httpStatus === null) {
                    $ignored++;
                    continue;
                }

                $final = $finalRepository->findOneBy([
                    'id' => $productId,
                    'competitor' => $competitor,
                ]);
                if (!$final instanceof CompetitorUrlFinal) {
                    $ignored++;
                    continue;
                }

                if ($final->getUrl() !== $url) {
                    $ignored++;
                    continue;
                }

                $final->setLastHttpStatus($httpStatus);
                $final->setLastHttpErrorAt(new \DateTimeImmutable());
                $final->setLastHttpErrorMessage($message !== '' ? mb_substr($message, 0, 255) : null);

                if (in_array($httpStatus, [404, 410], true)) {
                    $final->setConsecutiveHttpFailures($final->getConsecutiveHttpFailures() + 1);
                } else {
                    $final->setConsecutiveHttpFailures(0);
                }

                $failures++;

                if (
                    in_array($httpStatus, [404, 410], true)
                    && $final->getConsecutiveHttpFailures() >= self::GONE_HTTP_STATUS_THRESHOLD
                ) {
                    $testResult = $testResultRepository->findOneBy([
                        'productId' => $productId,
                        'competitor' => $competitor,
                    ]);
                    if ($testResult instanceof CompetitorUrlTestResult) {
                        $testResult->setCompetitorPageStatus(CompetitorUrlTestResult::PAGE_GONE);
                        $testResult->touch();
                        $gone++;
                    }

                    $this->entityManager->remove($final);
                    $removed++;
                }
            }
        }

        $this->entityManager->flush();

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'ignored' => $ignored,
            'failures' => $failures,
            'removed' => $removed,
            'gone' => $gone,
        ];
    }

    private function nullableDecimalString(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $value = str_replace(',', '.', $value);
        if (!is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
