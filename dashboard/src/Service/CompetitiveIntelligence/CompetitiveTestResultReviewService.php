<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use App\Entity\CompetitorUrlRejectedUrl;
use App\Entity\CompetitorUrlTestResult;
use App\Entity\CompetitorUrlFinal;
use Doctrine\ORM\EntityManagerInterface;

final class CompetitiveTestResultReviewService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CompetitivePriceHistoryService $priceHistoryService,
    ) {
    }

    public function updateReviewStatus(int $productId, int $competitorId, string $status): CompetitorUrlTestResult
    {
        $repository = $this->entityManager->getRepository(CompetitorUrlTestResult::class);
        $testResult = $repository->findOneBy([
            'productId' => $productId,
            'competitor' => $this->entityManager->getReference(\App\Entity\Competitor::class, $competitorId),
        ]);

        if (!$testResult instanceof CompetitorUrlTestResult) {
            throw new \RuntimeException(sprintf('Unknown competitor_url_test_result for product "%d" and competitor "%d".', $productId, $competitorId));
        }

        if (!in_array($status, [
            CompetitorUrlTestResult::REVIEW_POSTPONED,
            CompetitorUrlTestResult::REVIEW_VALID,
            CompetitorUrlTestResult::REVIEW_REJECTED,
        ], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid review status "%s".', $status));
        }

        $testResult->setValidationStatus($status);

        if ($status === CompetitorUrlTestResult::REVIEW_VALID) {
            $this->upsertFinal($testResult);
        } elseif ($status === CompetitorUrlTestResult::REVIEW_REJECTED) {
            $this->deleteFinal($testResult);
            $this->insertRejectedUrlIfNeeded($testResult);
        }

        $this->entityManager->flush();

        return $testResult;
    }

    private function upsertFinal(CompetitorUrlTestResult $testResult): void
    {
        $competitor = $testResult->getCompetitor();
        $finalRepository = $this->entityManager->getRepository(CompetitorUrlFinal::class);
        $existing = $finalRepository->findOneBy([
            'id' => $testResult->getProductId(),
            'competitor' => $competitor,
        ]);

        if ($existing instanceof CompetitorUrlFinal) {
            if ($existing->getUrl() !== (string) $testResult->getUrl()) {
                $existing->setUrl((string) $testResult->getUrl());
            }
            if ($testResult->getCompetitorPrice() !== null) {
                $existing->setCompetitorPrice($testResult->getCompetitorPrice());
                $this->priceHistoryService->recordObservation(
                    $competitor,
                    $testResult->getProductId(),
                    (string) $testResult->getUrl(),
                    $testResult->getCompetitorPrice(),
                    'review',
                );
            }
            return;
        }

        $this->entityManager->persist(new CompetitorUrlFinal(
            $testResult->getProductId(),
            $competitor,
            (string) $testResult->getUrl(),
            $testResult->getCompetitorPrice(),
        ));
        $this->priceHistoryService->recordObservation(
            $competitor,
            $testResult->getProductId(),
            (string) $testResult->getUrl(),
            $testResult->getCompetitorPrice(),
            'review',
        );
    }

    private function deleteFinal(CompetitorUrlTestResult $testResult): void
    {
        $finalRepository = $this->entityManager->getRepository(CompetitorUrlFinal::class);
        $existing = $finalRepository->findOneBy([
            'id' => $testResult->getProductId(),
            'competitor' => $testResult->getCompetitor(),
        ]);

        if ($existing instanceof CompetitorUrlFinal) {
            $this->entityManager->remove($existing);
        }
    }

    private function insertRejectedUrlIfNeeded(CompetitorUrlTestResult $testResult): void
    {
        if ($testResult->getUrl() === null || trim($testResult->getUrl()) === '') {
            return;
        }

        $repository = $this->entityManager->getRepository(CompetitorUrlRejectedUrl::class);
        $existing = $repository->findOneBy([
            'competitor' => $testResult->getCompetitor(),
            'url' => $testResult->getUrl(),
        ]);

        if ($existing instanceof CompetitorUrlRejectedUrl) {
            return;
        }

        $this->entityManager->persist(new CompetitorUrlRejectedUrl(
            $testResult->getCompetitor(),
            (string) $testResult->getUrl(),
        ));
    }
}
