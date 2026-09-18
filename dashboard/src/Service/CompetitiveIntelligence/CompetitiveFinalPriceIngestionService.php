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
                $ignored++;
                continue;
            }
            $resolvedUrl = trim((string) ($observation['resolved_url'] ?? ''));
            if ($resolvedUrl !== '' && $resolvedUrl !== $url) {
                if (!$this->isWoodbrassProductUrl($url) || !$this->isWoodbrassProductUrl($resolvedUrl)
                    || !in_array(strtolower($competitor->getDomain()), ['woodbrass.com', 'www.woodbrass.com'], true)) {
                    $ignored++;
                    continue;
                }
                $existingTarget = $finalRepository->findOneBy(['competitor' => $competitor, 'url' => $resolvedUrl]);
                if ($existingTarget instanceof CompetitorUrlFinal && $existingTarget->getId() !== $productId) {
                    $ignored++;
                    continue;
                }
                $final->setUrl($resolvedUrl);
                $testResult = $testResultRepository->findOneBy(['productId' => $productId, 'competitor' => $competitor]);
                if ($testResult instanceof CompetitorUrlTestResult && $testResult->getUrl() === $url) {
                    $testResult->setUrl($resolvedUrl);
                    $testResult->touch();
                }
                $url = $resolvedUrl;
            }
            $final->setCompetitorPrice($price);
            $final->resetHttpFailureState();
            $final->recordPriceAttempt('price_found');

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
                $result = (string) ($failure['result'] ?? (in_array($httpStatus, [404, 410], true) ? 'http_gone' : 'temporary_error'));
                if ($productId <= 0 || $url === '' || !in_array($result, ['price_not_found', 'temporary_error', 'http_gone'], true)
                    || ($result === 'http_gone' && !in_array($httpStatus, [404, 410], true))) {
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

                $final->recordPriceAttempt($result);
                if ($result === 'price_not_found') {
                    $final->resetHttpFailureState();
                    $failures++;
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

    private function isWoodbrassProductUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && strlen($url) <= 2048
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && in_array(strtolower($parts['host'] ?? ''), ['woodbrass.com', 'www.woodbrass.com'], true)
            && !isset($parts['user']) && !isset($parts['pass'])
            && preg_match('~^/(?:products/[^/]+/?|[^/]*-p[0-9]+\.html)$~', $parts['path'] ?? '') === 1;
    }

    private function nullableDecimalString(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $value = str_replace(',', '.', $value);
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
