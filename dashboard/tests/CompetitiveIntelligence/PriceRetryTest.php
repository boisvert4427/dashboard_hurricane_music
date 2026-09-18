<?php

declare(strict_types=1);

namespace App\Tests\CompetitiveIntelligence;

use App\Entity\Competitor;
use App\Entity\CompetitorUrlFinal;
use App\Entity\CompetitorUrlPriceHistory;
use App\Entity\CompetitorUrlTestResult;
use App\Service\CompetitiveIntelligence\CompetitiveFinalPriceIngestionService;
use App\Service\CompetitiveIntelligence\FinalUrlPriceBatchProvider;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class PriceRetryTest extends TestCase
{
    private EntityManager $em;
    private Competitor $competitor;
    private CompetitiveFinalPriceIngestionService $ingestion;
    private FinalUrlPriceBatchProvider $provider;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2) . '/src/Entity'], true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($this->em))->createSchema(array_map(
            $this->em->getClassMetadata(...),
            [Competitor::class, CompetitorUrlFinal::class, CompetitorUrlPriceHistory::class, CompetitorUrlTestResult::class],
        ));
        $this->competitor = new Competitor('Stars Music', 'stars-music.fr', '/search');
        $this->em->persist($this->competitor);
        $this->em->flush();
        $this->ingestion = new CompetitiveFinalPriceIngestionService($this->em);
        $this->provider = new FinalUrlPriceBatchProvider($this->em->getConnection());
    }

    private function final(int $id): CompetitorUrlFinal
    {
        $final = new CompetitorUrlFinal($id, $this->competitor, 'https://example.test/' . $id, '100.00');
        $this->em->persist($final);
        $this->em->flush();
        return $final;
    }

    private function recordFailure(CompetitorUrlFinal $final, string $result = 'price_not_found', ?int $status = null): array
    {
        return $this->ingestion->ingest([
            'competitor_id' => $this->competitor->getId(),
            'failures' => [['id_product' => $final->getId(), 'url' => $final->getUrl(), 'result' => $result, 'http_status' => $status]],
        ]);
    }

    public function testBackoffAndSuccessResetKeepLastKnownPrice(): void
    {
        $final = $this->final(1);
        $now = new \DateTimeImmutable('2026-09-17 12:00:00');
        foreach ([1, 7, 30, 30] as $index => $days) {
            $final->recordPriceAttempt('price_not_found', $now);
            self::assertEquals($now->modify('+' . $days . ' days'), $final->getNextPriceCheckAt());
            self::assertSame($index + 1, $final->getConsecutivePriceNotFound());
        }
        $final->recordPriceAttempt('temporary_error', $now);
        self::assertEquals($now->modify('+3 hours'), $final->getNextPriceCheckAt());
        self::assertSame(4, $final->getConsecutivePriceNotFound());
        self::assertSame('100.00', $final->getCompetitorPrice());
        $final->recordPriceAttempt('price_found', $now);
        self::assertNull($final->getNextPriceCheckAt());
        self::assertSame(0, $final->getConsecutivePriceNotFound());
        $final->recordPriceAttempt('price_not_found', $now);
        self::assertEquals($now->modify('+1 day'), $final->getNextPriceCheckAt());
    }

    public function testFailedPriceLeavesQueueAndSuccessfulObservationRestoresRotation(): void
    {
        $first = $this->final(1);
        $this->final(2);
        $this->recordFailure($first);
        $id = $this->competitor->getId();
        self::assertSame([2], array_column($this->provider->getNextBatch($id, 10)['items'], 'id_product'));
        self::assertSame('100.00', $first->getCompetitorPrice());
        self::assertSame(0, $this->em->getRepository(CompetitorUrlPriceHistory::class)->count([]));
        $stats = $this->ingestion->ingest(['competitor_id' => $id, 'observations' => [
            ['id_product' => 1, 'url' => $first->getUrl(), 'price' => 90],
        ]]);
        self::assertSame(1, $stats['updated']);
        self::assertSame('90.00', $first->getCompetitorPrice());
        self::assertNull($first->getNextPriceCheckAt());
        self::assertSame(0, $first->getConsecutivePriceNotFound());
        self::assertSame([2, 1], array_column($this->provider->getNextBatch($id, 10)['items'], 'id_product'));
        self::assertSame(1, $this->em->getRepository(CompetitorUrlPriceHistory::class)->count([]));
    }

    public function testDueOnlyPendingWorkManualPriorityAndTargetedBatch(): void
    {
        $first = $this->final(1);
        $second = $this->final(2);
        $this->recordFailure($first);
        $this->recordFailure($second, 'temporary_error', 429);
        $id = $this->competitor->getId();
        self::assertFalse($this->provider->hasPendingWork($id));
        self::assertSame([], $this->provider->getNextBatch($id)['items']);
        $second->requestPriceCheck();
        $this->em->flush();
        self::assertTrue($this->provider->hasPendingWork($id));
        $this->final(3);
        self::assertSame([2, 3], array_column($this->provider->getNextBatch($id, 10)['items'], 'id_product'));
        self::assertSame([2], array_column($this->provider->getNextBatch($id, 10, 0, 2)['items'], 'id_product'));
        self::assertSame([], $this->provider->getNextBatch($id, 10, 0, 1)['items']);
        // A due retry becomes eligible again, including through the wrap-around cursor.
        $first->recordPriceAttempt('temporary_error', new \DateTimeImmutable('-4 hours'));
        $this->em->flush();
        self::assertContains(1, array_column($this->provider->getNextBatch($id, 10, 999)['items'], 'id_product'));
    }

    public function testGoneThresholdAndStaleResponses(): void
    {
        $final = $this->final(1);
        $oldUrl = $final->getUrl();
        $this->recordFailure($final);
        $final->setUrl('https://example.test/new');
        $this->em->flush();
        self::assertNull($final->getNextPriceCheckAt());
        $stats = $this->ingestion->ingest(['competitor_id' => $this->competitor->getId(), 'observations' => [
            ['id_product' => 1, 'url' => $oldUrl, 'price' => 12],
        ], 'failures' => [['id_product' => 1, 'url' => $oldUrl, 'result' => 'price_not_found']]]);
        self::assertSame(2, $stats['ignored']);
        self::assertSame('100.00', $final->getCompetitorPrice());
        self::assertSame(0, $final->getConsecutivePriceNotFound());
        self::assertSame(0, $this->recordFailure($final, 'http_gone', 404)['removed']);
        self::assertSame(0, $this->recordFailure($final, 'http_gone', 410)['removed']);
        self::assertSame(1, $this->recordFailure($final, 'http_gone', 404)['removed']);
    }
    public function testWoodbrassRedirectUpdatesFinalAndValidationButPreservesHistory(): void
    {
        $this->competitor->setDomain('woodbrass.com');
        $final = $this->final(1);
        $oldUrl = 'https://www.woodbrass.com/guitare-p395764.html';
        $newUrl = 'https://woodbrass.com/products/fender-395764';
        $final->setUrl($oldUrl);
        $review = new CompetitorUrlTestResult(1, $this->competitor, 'matched', $oldUrl);
        $this->em->persist($review);
        $this->em->persist(new CompetitorUrlPriceHistory(1, $this->competitor, $oldUrl, '871.00'));
        $this->em->flush();
        $stats = $this->ingestion->ingest(['competitor_id' => $this->competitor->getId(), 'observations' => [
            ['id_product' => 1, 'url' => $oldUrl, 'resolved_url' => $newUrl, 'price' => 819],
        ]]);
        self::assertSame(1, $stats['updated']);
        self::assertSame($newUrl, $final->getUrl());
        self::assertSame($newUrl, $review->getUrl());
        self::assertSame('819.00', $final->getCompetitorPrice());
        self::assertSame(1, $this->em->getRepository(CompetitorUrlPriceHistory::class)->count(['url' => $oldUrl]));
        self::assertSame(1, $this->em->getRepository(CompetitorUrlPriceHistory::class)->count(['url' => $newUrl]));
        $stale = $this->ingestion->ingest(['competitor_id' => $this->competitor->getId(), 'observations' => [
            ['id_product' => 1, 'url' => $oldUrl, 'resolved_url' => $newUrl, 'price' => 800],
        ]]);
        self::assertSame(1, $stale['ignored']);
        self::assertSame('819.00', $final->getCompetitorPrice());
    }

    public function testWoodbrassUnsafeAndConflictingRedirectsAreIgnored(): void
    {
        $this->competitor->setDomain('woodbrass.com');
        $final = $this->final(1);
        $final->setUrl('https://www.woodbrass.com/guitare-p395764.html');
        $other = $this->final(2);
        $other->setUrl('https://woodbrass.com/products/other');
        $this->em->flush();
        foreach (['https://evil.test/products/fender', 'https://woodbrass.com/', 'https://woodbrass.com/collections/guitares', $other->getUrl()] as $target) {
            $stats = $this->ingestion->ingest(['competitor_id' => $this->competitor->getId(), 'observations' => [
                ['id_product' => 1, 'url' => $final->getUrl(), 'resolved_url' => $target, 'price' => 819],
            ]]);
            self::assertSame(1, $stats['ignored']);
            self::assertSame('100.00', $final->getCompetitorPrice());
        }
    }

}
