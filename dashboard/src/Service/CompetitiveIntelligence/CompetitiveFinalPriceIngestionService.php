<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use App\Entity\Competitor;
use App\Entity\CompetitorUrlFinal;
use App\Entity\CompetitorUrlPriceHistory;
use Doctrine\ORM\EntityManagerInterface;

final class CompetitiveFinalPriceIngestionService
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

        $observations = $payload['observations'] ?? [];
        if (!is_array($observations)) {
            throw new \RuntimeException('observations must be an array.');
        }

        $inserted = 0;
        $updated = 0;
        $ignored = 0;
        $finalRepository = $this->entityManager->getRepository(CompetitorUrlFinal::class);

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

            $this->entityManager->persist(new CompetitorUrlPriceHistory(
                $productId,
                $competitor,
                $url,
                $price,
                (string) ($observation['source'] ?? 'final_price'),
            ));

            $updated++;
        }

        $this->entityManager->flush();

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'ignored' => $ignored,
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
