<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use App\Entity\Competitor;
use App\Entity\CompetitorUrlPriceHistory;
use Doctrine\ORM\EntityManagerInterface;

final class CompetitivePriceHistoryService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function recordObservation(
        Competitor $competitor,
        int $productId,
        string $url,
        ?string $price,
        ?string $source = null,
    ): void {
        $price = $this->nullableDecimalString($price);
        if ($price === null || $productId <= 0 || trim($url) === '') {
            return;
        }

        $this->entityManager->persist(new CompetitorUrlPriceHistory(
            $productId,
            $competitor,
            $url,
            $price,
            $source,
        ));
    }

    private function nullableDecimalString(?string $value): ?string
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
