<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

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
        $this->entityManager->flush();

        return $candidate;
    }
}
