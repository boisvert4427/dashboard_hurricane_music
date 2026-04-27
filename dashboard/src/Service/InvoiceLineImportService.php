<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use RuntimeException;

final class InvoiceLineImportService
{
    public function __construct(
        private readonly Connection $reportingConnection,
        private readonly Connection $prestashopConnection,
    ) {
    }

    /**
     * @return array{inserted:int, source_rows:int}
     */
    public function run(int $batchSize = 500, ?int $maxRows = null): array
    {
        $this->createSchemaIfNeeded();

        try {
            $this->reportingConnection->executeStatement('DELETE FROM reporting_invoice_line_fact');
        } catch (Exception $e) {
            throw new RuntimeException('Unable to clear reporting table: ' . $e->getMessage(), 0, $e);
        }

        $lastId = 0;
        $inserted = 0;
        $sourceRows = 0;
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        while (true) {
            if ($maxRows !== null) {
                $remaining = $maxRows - $sourceRows;
                if ($remaining <= 0) {
                    break;
                }

                $batchSize = min($batchSize, $remaining);
            }

            try {
                $rows = $this->prestashopConnection->fetchAllAssociative(
                    sprintf(
                        <<<SQL
                        SELECT
                            IDLigneFac,
                            IDFAC,
                            NumFacPoste,
                            IDART,
                            CODE,
                            DESIGNATION_PRODUIT,
                            IDCLI,
                            SITE,
                            MODE_VENTE,
                            WEB,
                            NO_WEB,
                            Q_FAC,
                            PrixTTC,
                            TotalHT,
                            TotalTTC,
                            MARGE,
                            Remise,
                            RemiseMontant,
                            TauxTVA,
                            OrigineData,
                            TypePiece,
                            DH_Facture,
                            DateFacture,
                            HeureFacture
                        FROM K_LI_FAC
                        WHERE IDLigneFac > %d
                        ORDER BY IDLigneFac ASC
                        LIMIT %d
                        SQL,
                        $lastId,
                        $batchSize
                    )
                );
            } catch (Exception $e) {
                throw new RuntimeException('Unable to read K_LI_FAC: ' . $e->getMessage(), 0, $e);
            }

            if ($rows === []) {
                break;
            }

            $articleRows = $this->loadArticlesByIds($rows);
            $brandNames = $this->loadBrandNamesByArticles($articleRows);
            $payload = [];

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                if ($this->shouldExcludeRow($row, $articleRows[(int) ($row['IDART'] ?? 0)] ?? null)) {
                    $lastId = max($lastId, (int) ($row['IDLigneFac'] ?? 0));
                    continue;
                }

                $article = $articleRows[(int) ($row['IDART'] ?? 0)] ?? null;
                $payload[] = $this->mapRow($row, $article, $brandNames, $now);
                $lastId = max($lastId, (int) ($row['IDLigneFac'] ?? 0));
            }

            if ($payload !== []) {
                $this->insertBatch($payload);
                $inserted += count($payload);
                $sourceRows += count($payload);
            }

            if (count($rows) < $batchSize || ($maxRows !== null && $sourceRows >= $maxRows)) {
                break;
            }
        }

        return [
            'inserted' => $inserted,
            'source_rows' => $sourceRows,
        ];
    }

    private function createSchemaIfNeeded(): void
    {
        $exists = $this->reportingConnection->fetchOne("SHOW TABLES LIKE 'reporting_invoice_line_fact'");
        if ($exists) {
            $this->ensureColumnExists('brand_name', <<<'SQL'
                ALTER TABLE reporting_invoice_line_fact
                    ADD COLUMN brand_name VARCHAR(255) DEFAULT NULL AFTER brand_id,
                    ADD KEY idx_brand_name (brand_name)
            SQL);

            return;
        }

        $sql = <<<'SQL'
            CREATE TABLE reporting_invoice_line_fact (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source_line_id INT NOT NULL,
                source_invoice_id INT NOT NULL,
                invoice_number VARCHAR(64) NOT NULL,
                invoice_date DATE NOT NULL,
                invoice_datetime DATETIME NOT NULL,
                product_id INT NOT NULL,
                product_code VARCHAR(50) NOT NULL,
                product_name VARCHAR(255) NOT NULL,
                ray_id INT DEFAULT NULL,
                family_id INT DEFAULT NULL,
                subfamily_id INT DEFAULT NULL,
                brand_id INT DEFAULT NULL,
                brand_name VARCHAR(255) DEFAULT NULL,
                supplier_id INT DEFAULT NULL,
                supplier_name VARCHAR(252) DEFAULT NULL,
                supplier_reference VARCHAR(25) DEFAULT NULL,
                customer_id INT DEFAULT NULL,
                mode_vente VARCHAR(10) DEFAULT NULL,
                channel_code VARCHAR(10) DEFAULT NULL,
                channel_name VARCHAR(50) DEFAULT NULL,
                quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
                unit_price_ttc DECIMAL(10,2) NOT NULL DEFAULT 0,
                total_ht DECIMAL(10,2) NOT NULL DEFAULT 0,
                total_ttc DECIMAL(10,2) NOT NULL DEFAULT 0,
                margin_ht DECIMAL(10,2) NOT NULL DEFAULT 0,
                discount_percent DECIMAL(4,2) NOT NULL DEFAULT 0,
                discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                tax_rate DECIMAL(4,2) NOT NULL DEFAULT 0,
                raw_origin VARCHAR(2) DEFAULT NULL,
                raw_type_piece VARCHAR(10) DEFAULT NULL,
                imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                UNIQUE KEY uk_source_line_id (source_line_id),
                KEY idx_invoice_date (invoice_date),
                KEY idx_source_invoice_id (source_invoice_id),
                KEY idx_product_id (product_id),
                KEY idx_brand_id (brand_id),
                KEY idx_brand_name (brand_name),
                KEY idx_family_id (family_id),
                KEY idx_customer_id (customer_id),
                KEY idx_channel_name (channel_name),
                KEY idx_invoice_date_channel (invoice_date, channel_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;

        $this->reportingConnection->executeStatement($sql);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function loadArticlesByIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['IDART'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        try {
            $articleRows = $this->prestashopConnection->fetchAllAssociative(
                'SELECT IDART, DESIGNATION, CODE, IDRAY, IDFAM, IDSSFAM, ID_FAB, supplier, REF_FOU, IDFOU FROM K_ARTICLE WHERE IDART IN (:ids)',
                ['ids' => array_values($ids)],
                ['ids' => ArrayParameterType::INTEGER]
            );
        } catch (Exception) {
            return [];
        }

        $indexed = [];
        foreach ($articleRows as $articleRow) {
            if (!is_array($articleRow) || !isset($articleRow['IDART'])) {
                continue;
            }

            $indexed[(int) $articleRow['IDART']] = $articleRow;
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $articles
     * @return array<int, string>
     */
    private function loadBrandNamesByArticles(array $articles): array
    {
        $ids = [];
        foreach ($articles as $article) {
            $brandId = (int) ($article['ID_FAB'] ?? 0);
            if ($brandId > 0) {
                $ids[$brandId] = $brandId;
            }
        }

        if ($ids === []) {
            return [];
        }

        try {
            $brandRows = $this->prestashopConnection->fetchAllAssociative(
                'SELECT IDFAB, NOM_FAB FROM WEB_FABRICANT WHERE IDFAB IN (:ids)',
                ['ids' => array_values($ids)],
                ['ids' => ArrayParameterType::INTEGER]
            );
        } catch (Exception) {
            return [];
        }

        $indexed = [];
        foreach ($brandRows as $brandRow) {
            if (!is_array($brandRow) || !isset($brandRow['IDFAB'])) {
                continue;
            }

            $indexed[(int) $brandRow['IDFAB']] = $this->value($brandRow['NOM_FAB'] ?? '');
        }

        return $indexed;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $article
     * @param array<int, string> $brandNames
     * @return array<int, mixed>
     */
    private function mapRow(array $row, ?array $article, array $brandNames, string $now): array
    {
        [$invoiceDate, $invoiceDateTime] = $this->resolveInvoiceTimestamps($row);

        $articleCode = is_array($article) ? ($article['CODE'] ?? '') : '';
        $articleDesignation = is_array($article) ? ($article['DESIGNATION'] ?? '') : '';
        $articleRay = is_array($article) ? ($article['IDRAY'] ?? null) : null;
        $articleFamily = is_array($article) ? ($article['IDFAM'] ?? null) : null;
        $articleSubfamily = is_array($article) ? ($article['IDSSFAM'] ?? null) : null;
        $articleBrand = is_array($article) ? ($article['ID_FAB'] ?? null) : null;
        $articleSupplierId = is_array($article) ? ($article['IDFOU'] ?? null) : null;
        $articleSupplierName = is_array($article) ? ($article['supplier'] ?? '') : '';
        $articleSupplierReference = is_array($article) ? ($article['REF_FOU'] ?? '') : '';
        $articleBrandName = is_array($article) ? ($brandNames[(int) ($article['ID_FAB'] ?? 0)] ?? '') : '';
        [$channelCode, $channelName] = $this->resolveChannel($row, $article);

        $productCode = $this->value($articleCode !== '' ? $articleCode : ($row['CODE'] ?? ''));
        $productName = $this->value($articleDesignation !== '' ? $articleDesignation : ($row['DESIGNATION_PRODUIT'] ?? ''));

        return [
            (int) ($row['IDLigneFac'] ?? 0),
            (int) ($row['IDFAC'] ?? 0),
            $this->normalizeInvoiceNumber($row),
            $invoiceDate,
            $invoiceDateTime,
            (int) ($row['IDART'] ?? 0),
            $productCode,
            $productName,
            $this->intOrNull($articleRay),
            $this->intOrNull($articleFamily),
            $this->intOrNull($articleSubfamily),
            $this->intOrNull($articleBrand),
            $this->value($articleBrandName),
            $this->intOrNull($articleSupplierId),
            $this->value($articleSupplierName),
            $this->value($articleSupplierReference),
            $this->intOrNull($row['IDCLI'] ?? null),
            $this->value($row['MODE_VENTE'] ?? ''),
            $channelCode,
            $channelName,
            $this->floatOrZero($row['Q_FAC'] ?? 0),
            $this->floatOrZero($row['PrixTTC'] ?? 0),
            $this->floatOrZero($row['TotalHT'] ?? 0),
            $this->floatOrZero($row['TotalTTC'] ?? 0),
            $this->floatOrZero($row['MARGE'] ?? 0),
            $this->floatOrZero($row['Remise'] ?? 0),
            $this->floatOrZero($row['RemiseMontant'] ?? 0),
            $this->floatOrZero($row['TauxTVA'] ?? 0),
            $this->value($row['OrigineData'] ?? ''),
            $this->value($row['TypePiece'] ?? ''),
            $now,
            $now,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $article
     */
    private function shouldExcludeRow(array $row, ?array $article): bool
    {
        $articleId = (int) ($row['IDART'] ?? 0);
        if ($articleId === 18823) {
            return true;
        }

        if ((float) ($row['Q_FAC'] ?? 0) === 0.0) {
            return true;
        }

        $articleRef = $this->value($article['REF_FOU'] ?? '');
        if ($articleRef !== '' && str_starts_with(strtoupper($articleRef), 'REPRISE')) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    private function insertBatch(array $rows): void
    {
        $columns = [
            'source_line_id',
            'source_invoice_id',
            'invoice_number',
            'invoice_date',
            'invoice_datetime',
            'product_id',
            'product_code',
            'product_name',
            'ray_id',
            'family_id',
            'subfamily_id',
            'brand_id',
            'brand_name',
            'supplier_id',
            'supplier_name',
            'supplier_reference',
            'customer_id',
            'mode_vente',
            'channel_code',
            'channel_name',
            'quantity',
            'unit_price_ttc',
            'total_ht',
            'total_ttc',
            'margin_ht',
            'discount_percent',
            'discount_amount',
            'tax_rate',
            'raw_origin',
            'raw_type_piece',
            'imported_at',
            'updated_at',
        ];

        $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $valuesSql = implode(',', array_fill(0, count($rows), $placeholders));
        $sql = 'INSERT INTO reporting_invoice_line_fact (' . implode(',', $columns) . ') VALUES ' . $valuesSql;

        $params = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $params[] = $value;
            }
        }

        $this->reportingConnection->executeStatement($sql, $params);
    }

    private function ensureColumnExists(string $columnName, string $alterSql): void
    {
        $exists = $this->reportingConnection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporting_invoice_line_fact' AND COLUMN_NAME = :column_name",
            ['column_name' => $columnName]
        );

        if ((int) $exists > 0) {
            return;
        }

        $this->reportingConnection->executeStatement($alterSql);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0: string, 1: string}
     */
    private function resolveInvoiceTimestamps(array $row): array
    {
        $datetime = $this->parseDateTime($row['DH_Facture'] ?? null);
        if ($datetime instanceof DateTimeImmutable) {
            return [$datetime->format('Y-m-d'), $datetime->format('Y-m-d H:i:s')];
        }

        $dateOnly = $this->parseDateTime($row['DateFacture'] ?? null);
        if ($dateOnly instanceof DateTimeImmutable) {
            $time = $this->value($row['HeureFacture'] ?? '');
            $combined = $dateOnly->format('Y-m-d') . ' ' . ($time !== '' ? $time : '00:00:00');

            try {
                $combinedDateTime = new DateTimeImmutable($combined);
            } catch (\Throwable) {
                $combinedDateTime = $dateOnly->setTime(0, 0);
            }

            return [$dateOnly->format('Y-m-d'), $combinedDateTime->format('Y-m-d H:i:s')];
        }

        $today = new DateTimeImmutable('today');

        return [$today->format('Y-m-d'), $today->format('Y-m-d') . ' 00:00:00'];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0: string, 1: string}
     */
    private function resolveChannel(array $row, ?array $article = null): array
    {
        $articleRay = is_array($article) ? (int) ($article['IDRAY'] ?? 0) : 0;
        if ($articleRay === 2) {
            return ['ECOLE', 'École'];
        }

        $webFlag = (int) ($row['WEB'] ?? 0);
        $site = (int) ($row['SITE'] ?? -1);

        if ($webFlag === 1) {
            return ['WEB', 'Web'];
        }

        if ($site === 0) {
            return ['NANTES', 'Nantes'];
        }

        if ($site === 1) {
            return ['BORDEAUX', 'Bordeaux'];
        }

        return ['AUTRE', 'Autre'];
    }

    private function normalizeInvoiceNumber(array $row): string
    {
        $invoiceNumber = (string) ((int) ($row['IDFAC'] ?? 0));

        if ($invoiceNumber !== '0') {
            return $invoiceNumber;
        }

        return $this->value($row['NumFacPoste'] ?? '');
    }

    private function value(mixed $value): string
    {
        return trim((string) $value);
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int) $value;

        return $intValue === 0 ? null : $intValue;
    }

    private function floatOrZero(mixed $value): float
    {
        return (float) ($value ?? 0);
    }

    private function parseDateTime(mixed $value): ?DateTimeImmutable
    {
        $string = $this->value($value);

        if ($string === '' || $string === '0000-00-00' || $string === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return new DateTimeImmutable($string);
        } catch (\Throwable) {
            return null;
        }
    }
}
