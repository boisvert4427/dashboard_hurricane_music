<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ProductDimensionSyncService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.reporting_connection')]
        private readonly Connection $reportingConnection,
        #[Autowire(service: 'doctrine.dbal.prestashop_connection')]
        private readonly Connection $prestashopConnection,
    ) {
    }

    /**
     * @return array{products:int, historical_lines_updated:int}
     */
    public function sync(int $batchSize = 500): array
    {
        $this->createSchemaIfNeeded();
        $lastProductId = 0;
        $products = 0;
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        do {
            $rows = $this->prestashopConnection->fetchAllAssociative(
                <<<'SQL'
                    SELECT
                        a.IDART AS product_id,
                        a.CODE AS product_code,
                        a.DESIGNATION AS product_name,
                        a.IDRAY AS ray_id,
                        r.Rayon AS rayon_name,
                        a.IDFAM AS family_id,
                        f.Famille AS family_name,
                        a.IDSSFAM AS subfamily_id,
                        sf.SSFam AS subfamily_name,
                        a.ID_FAB AS brand_id,
                        fab.NOM_FAB AS brand_name,
                        a.IDFOU AS supplier_id,
                        a.supplier AS supplier_name,
                        a.REF_FOU AS supplier_reference
                    FROM K_ARTICLE a
                    LEFT JOIN WEB_RAYON r ON r.IDRAY = a.IDRAY
                    LEFT JOIN WEB_FAMILLE f ON f.IDFAM = a.IDFAM
                    LEFT JOIN WEB_SSFAMILLE sf ON sf.IDSSFAM = a.IDSSFAM
                    LEFT JOIN WEB_FABRICANT fab ON fab.IDFAB = a.ID_FAB
                    WHERE a.IDART > :last_product_id
                    ORDER BY a.IDART ASC
                    LIMIT :batch_size
                SQL,
                ['last_product_id' => $lastProductId, 'batch_size' => $batchSize],
                ['last_product_id' => ParameterType::INTEGER, 'batch_size' => ParameterType::INTEGER]
            );

            if ($rows === []) {
                break;
            }

            $this->upsertBatch($rows, $now);
            $products += count($rows);
            $lastProductId = (int) $rows[array_key_last($rows)]['product_id'];
        } while (count($rows) === $batchSize);

        return [
            'products' => $products,
            'historical_lines_updated' => $this->refreshHistoricalInvoiceMetadata(),
        ];
    }

    private function createSchemaIfNeeded(): void
    {
        $this->reportingConnection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS reporting_product_dimension (
                product_id INT NOT NULL,
                product_code VARCHAR(100) DEFAULT NULL,
                product_name VARCHAR(255) DEFAULT NULL,
                ray_id INT DEFAULT NULL,
                rayon_name VARCHAR(255) DEFAULT NULL,
                family_id INT DEFAULT NULL,
                family_name VARCHAR(255) DEFAULT NULL,
                subfamily_id INT DEFAULT NULL,
                subfamily_name VARCHAR(255) DEFAULT NULL,
                brand_id INT DEFAULT NULL,
                brand_name VARCHAR(255) DEFAULT NULL,
                supplier_id INT DEFAULT NULL,
                supplier_name VARCHAR(255) DEFAULT NULL,
                supplier_reference VARCHAR(100) DEFAULT NULL,
                synced_at DATETIME NOT NULL,
                PRIMARY KEY (product_id),
                KEY idx_product_dimension_brand (brand_id),
                KEY idx_product_dimension_supplier (supplier_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsertBatch(array $rows, string $now): void
    {
        $columns = [
            'product_id', 'product_code', 'product_name', 'ray_id', 'rayon_name',
            'family_id', 'family_name', 'subfamily_id', 'subfamily_name',
            'brand_id', 'brand_name', 'supplier_id', 'supplier_name',
            'supplier_reference', 'synced_at',
        ];
        $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $sql = 'INSERT INTO reporting_product_dimension (' . implode(',', $columns) . ') VALUES '
            . implode(',', array_fill(0, count($rows), $placeholders))
            . ' ON DUPLICATE KEY UPDATE '
            . implode(', ', array_map(
                static fn (string $column): string => $column . ' = VALUES(' . $column . ')',
                array_slice($columns, 1)
            ));

        $params = [];
        foreach ($rows as $row) {
            foreach (array_slice($columns, 0, -1) as $column) {
                $value = $row[$column] ?? null;
                $params[] = is_string($value) ? trim($value) : $value;
            }
            $params[] = $now;
        }

        $this->reportingConnection->executeStatement($sql, $params);
    }

    private function refreshHistoricalInvoiceMetadata(): int
    {
        $invoiceTableExists = $this->reportingConnection->fetchOne("SHOW TABLES LIKE 'reporting_invoice_line_fact'");
        if (!$invoiceTableExists) {
            return 0;
        }

        return $this->reportingConnection->executeStatement(<<<'SQL'
            UPDATE reporting_invoice_line_fact fact
            INNER JOIN reporting_product_dimension product ON product.product_id = fact.product_id
            SET
                fact.product_code = product.product_code,
                fact.product_name = product.product_name,
                fact.ray_id = product.ray_id,
                fact.rayon_name = product.rayon_name,
                fact.family_id = product.family_id,
                fact.family_name = product.family_name,
                fact.subfamily_id = product.subfamily_id,
                fact.subfamily_name = product.subfamily_name,
                fact.brand_id = product.brand_id,
                fact.brand_name = product.brand_name,
                fact.supplier_id = product.supplier_id,
                fact.supplier_name = product.supplier_name,
                fact.supplier_reference = product.supplier_reference,
                fact.updated_at = CURRENT_TIMESTAMP
            WHERE NOT (
                fact.product_code <=> product.product_code
                AND fact.product_name <=> product.product_name
                AND fact.ray_id <=> product.ray_id
                AND fact.rayon_name <=> product.rayon_name
                AND fact.family_id <=> product.family_id
                AND fact.family_name <=> product.family_name
                AND fact.subfamily_id <=> product.subfamily_id
                AND fact.subfamily_name <=> product.subfamily_name
                AND fact.brand_id <=> product.brand_id
                AND fact.brand_name <=> product.brand_name
                AND fact.supplier_id <=> product.supplier_id
                AND fact.supplier_name <=> product.supplier_name
                AND fact.supplier_reference <=> product.supplier_reference
            )
        SQL);
    }
}
