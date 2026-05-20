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

    public function deleteObservations(
        Competitor $competitor,
        int $productId,
        string $url,
    ): void {
        $url = trim($url);
        if ($productId <= 0 || $url === '') {
            return;
        }

        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM competitor_url_price_history WHERE competitor_id = :competitor_id AND id_product = :id_product AND url = :url',
            [
                'competitor_id' => $competitor->getId(),
                'id_product' => $productId,
                'url' => $url,
            ],
            [
                'competitor_id' => \Doctrine\DBAL\ParameterType::INTEGER,
                'id_product' => \Doctrine\DBAL\ParameterType::INTEGER,
                'url' => \Doctrine\DBAL\ParameterType::STRING,
            ]
        );
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
