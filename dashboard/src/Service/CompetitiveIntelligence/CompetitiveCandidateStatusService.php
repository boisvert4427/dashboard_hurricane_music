<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use App\Entity\CompetitorUrlFinal;
use App\Entity\CompetitorUrlCandidate;
use Doctrine\ORM\EntityManagerInterface;

final class CompetitiveCandidateStatusService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function updateStatus(int $candidateId, string $status): CompetitorUrlCandidate
    {
        $candidate = $this->entityManager->getRepository(CompetitorUrlCandidate::class)->find($candidateId);
        if (!$candidate instanceof CompetitorUrlCandidate) {
            throw new \RuntimeException(sprintf('Unknown competitor_url_candidate id "%d".', $candidateId));
        }

        if (!in_array($status, [
            CompetitorUrlCandidate::STATUS_PENDING,
            CompetitorUrlCandidate::STATUS_VALID,
            CompetitorUrlCandidate::STATUS_REJECTED,
        ], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid status "%s".', $status));
        }

        $candidate->setStatus($status);

        if ($status === CompetitorUrlCandidate::STATUS_REJECTED) {
            $this->removeTestAndFinalRows($candidate);
        } elseif ($status === CompetitorUrlCandidate::STATUS_VALID) {
            $this->upsertFinalRow($candidate);
        }

        $this->entityManager->flush();

        return $candidate;
    }

    private function removeTestAndFinalRows(CompetitorUrlCandidate $candidate): void
    {
        $testResult = $this->entityManager->getRepository(\App\Entity\CompetitorUrlTestResult::class)->findOneBy([
            'productId' => $candidate->getProductId(),
            'competitor' => $candidate->getCompetitor(),
        ]);
        if ($testResult instanceof \App\Entity\CompetitorUrlTestResult) {
            $this->entityManager->remove($testResult);
        }

        $final = $this->entityManager->getRepository(CompetitorUrlFinal::class)->findOneBy([
            'id' => $candidate->getProductId(),
            'competitor' => $candidate->getCompetitor(),
        ]);
        if ($final instanceof CompetitorUrlFinal) {
            $this->entityManager->remove($final);
        }
    }

    private function upsertFinalRow(CompetitorUrlCandidate $candidate): void
    {
        $finalRepository = $this->entityManager->getRepository(CompetitorUrlFinal::class);
        $existing = $finalRepository->findOneBy([
            'id' => $candidate->getProductId(),
            'competitor' => $candidate->getCompetitor(),
        ]);

        if ($existing instanceof CompetitorUrlFinal) {
            if ($existing->getUrl() !== $candidate->getUrl()) {
                $existing->setUrl($candidate->getUrl());
            }
            return;
        }

        $this->entityManager->persist(new CompetitorUrlFinal(
            $candidate->getProductId(),
            $candidate->getCompetitor(),
            $candidate->getUrl(),
        ));
    }
}
