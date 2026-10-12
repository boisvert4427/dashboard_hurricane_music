<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\StockCostCacheService;
use App\Service\StockCostCalculator;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class StockCostCacheServiceTest extends TestCase
{
    public function testItQueuesEverySiteForACompleteProductReplay(): void
    {
        $reporting = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $source = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $reporting->executeStatement(<<<'SQL'
            CREATE TABLE reporting_stock_recalc_queue (
                product_id INTEGER NOT NULL,
                site_id INTEGER NOT NULL,
                first_changed_source_id INTEGER NOT NULL,
                queued_at TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                last_error TEXT,
                PRIMARY KEY (product_id, site_id)
            )
        SQL);
        $source->executeStatement('CREATE TABLE K_HISTO_STOCK (IDHISTO_STOCK INTEGER, IDART INTEGER, SITE INTEGER)');
        $source->insert('K_HISTO_STOCK', ['IDHISTO_STOCK' => 30, 'IDART' => 33496, 'SITE' => 1]);
        $source->insert('K_HISTO_STOCK', ['IDHISTO_STOCK' => 10, 'IDART' => 33496, 'SITE' => 0]);
        $source->insert('K_HISTO_STOCK', ['IDHISTO_STOCK' => 20, 'IDART' => 33496, 'SITE' => 0]);

        $service = new StockCostCacheService($reporting, $source, new StockCostCalculator());

        self::assertSame([0, 1], $service->queueProductRecalculation(33496));
        self::assertSame([
            ['product_id' => 33496, 'site_id' => 0, 'first_changed_source_id' => 10],
            ['product_id' => 33496, 'site_id' => 1, 'first_changed_source_id' => 30],
        ], $reporting->fetchAllAssociative(
            'SELECT product_id, site_id, first_changed_source_id FROM reporting_stock_recalc_queue ORDER BY site_id'
        ));
        self::assertSame([], $service->queueProductRecalculation(99999));
    }
}
