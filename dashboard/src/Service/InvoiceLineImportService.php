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
    public function run(int $batchSize = 500, ?int $maxRows = null, ?string $sinceDate = null): array
    {
        $this->createSchemaIfNeeded();

        $sinceDate = $this->normalizeSinceDate($sinceDate);
        $lastId = $sinceDate !== null ? 0 : $this->getLastImportedSourceLineId();
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
                            l.IDLigneFac,
                            l.IDFAC,
                            l.NumFacPoste,
                            l.IDART,
                            l.CODE,
                            l.DESIGNATION_PRODUIT,
                            l.IDCLI,
                            l.SITE,
                            l.MODE_VENTE,
                            l.WEB,
                            l.NO_WEB,
                            l.Q_FAC,
                            l.PrixTTC,
                            l.TotalHT,
                            l.TotalTTC,
                            l.MARGE,
                            l.Remise,
                            l.RemiseMontant,
                            l.TauxTVA,
                            l.OrigineData,
                            l.TypePiece,
                            l.DH_Facture,
                            l.DateFacture,
                            l.HeureFacture,
                            a.DESIGNATION AS ARTICLE_DESIGNATION,
                            a.CODE AS ARTICLE_CODE,
                            a.IDRAY AS ARTICLE_IDRAY,
                            a.IDFAM AS ARTICLE_IDFAM,
                            a.IDSSFAM AS ARTICLE_IDSSFAM,
                            a.ID_FAB AS ARTICLE_ID_FAB,
                            a.supplier AS ARTICLE_SUPPLIER,
                            a.REF_FOU AS ARTICLE_REF_FOU,
                            a.IDFOU AS ARTICLE_IDFOU
                        FROM K_LI_FAC l
                        LEFT JOIN K_ARTICLE a ON a.IDART = l.IDART
                        WHERE l.IDLigneFac > %d
                        %s
                        ORDER BY l.IDLigneFac ASC
                        LIMIT %d
                        SQL,
                        $lastId,
                        $sinceDate !== null ? sprintf("AND COALESCE(DATE(l.DH_Facture), l.DateFacture) >= '%s'", $sinceDate) : '',
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

    private function normalizeSinceDate(?string $sinceDate): ?string
    {
        if ($sinceDate === null || $sinceDate === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $sinceDate);
        if ($date === false) {
            throw new RuntimeException('Invalid since date format, expected YYYY-MM-DD.');
        }

        return $date->format('Y-m-d');
    }

    private function getLastImportedSourceLineId(): int
    {
        try {
            return (int) $this->reportingConnection->fetchOne(
                'SELECT COALESCE(MAX(source_line_id), 0) FROM reporting_invoice_line_fact'
            );
        } catch (Exception) {
            return 0;
        }
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
        $this->ensureColumnExists('rayon_name', <<<'SQL'
                ALTER TABLE reporting_invoice_line_fact
                    ADD COLUMN rayon_name VARCHAR(255) DEFAULT NULL AFTER ray_id,
                    ADD KEY idx_rayon_name (rayon_name)
            SQL);
        $this->ensureColumnExists('family_name', <<<'SQL'
                ALTER TABLE reporting_invoice_line_fact
                    ADD COLUMN family_name VARCHAR(255) DEFAULT NULL AFTER family_id,
                    ADD KEY idx_family_name (family_name)
            SQL);
        $this->ensureColumnExists('subfamily_name', <<<'SQL'
                ALTER TABLE reporting_invoice_line_fact
                    ADD COLUMN subfamily_name VARCHAR(255) DEFAULT NULL AFTER subfamily_id,
                    ADD KEY idx_subfamily_name (subfamily_name)
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
                rayon_name VARCHAR(255) DEFAULT NULL,
                family_id INT DEFAULT NULL,
                family_name VARCHAR(255) DEFAULT NULL,
                subfamily_id INT DEFAULT NULL,
                subfamily_name VARCHAR(255) DEFAULT NULL,
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
                'SELECT a.IDART, a.DESIGNATION, a.CODE, a.IDRAY, r.Rayon AS rayon_name, a.IDFAM, f.Famille AS family_name, a.IDSSFAM, sf.SSFam AS subfamily_name, a.ID_FAB, a.supplier, a.REF_FOU, a.IDFOU FROM K_ARTICLE a LEFT JOIN WEB_RAYON r ON r.IDRAY = a.IDRAY LEFT JOIN WEB_FAMILLE f ON f.IDFAM = a.IDFAM LEFT JOIN WEB_SSFAMILLE sf ON sf.IDSSFAM = a.IDSSFAM WHERE a.IDART IN (:ids)',
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
        $articleRayonName = is_array($article) ? ($article['rayon_name'] ?? '') : '';
        $articleFamily = is_array($article) ? ($article['IDFAM'] ?? null) : null;
        $articleFamilyName = is_array($article) ? ($article['family_name'] ?? '') : '';
        $articleSubfamily = is_array($article) ? ($article['IDSSFAM'] ?? null) : null;
        $articleSubfamilyName = is_array($article) ? ($article['subfamily_name'] ?? '') : '';
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
            $this->value($articleRayonName),
            $this->intOrNull($articleFamily),
            $this->value($articleFamilyName),
            $this->intOrNull($articleSubfamily),
            $this->value($articleSubfamilyName),
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
            'rayon_name',
            'family_id',
            'family_name',
            'subfamily_id',
            'subfamily_name',
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
        $sql = 'INSERT INTO reporting_invoice_line_fact (' . implode(',', $columns) . ') VALUES ' . $valuesSql . ' ON DUPLICATE KEY UPDATE ' . implode(', ', [
            'source_invoice_id = VALUES(source_invoice_id)',
            'invoice_number = VALUES(invoice_number)',
            'invoice_date = VALUES(invoice_date)',
            'invoice_datetime = VALUES(invoice_datetime)',
            'product_id = VALUES(product_id)',
            'product_code = VALUES(product_code)',
            'product_name = VALUES(product_name)',
            'ray_id = VALUES(ray_id)',
            'rayon_name = VALUES(rayon_name)',
            'family_id = VALUES(family_id)',
            'family_name = VALUES(family_name)',
            'subfamily_id = VALUES(subfamily_id)',
            'subfamily_name = VALUES(subfamily_name)',
            'brand_id = VALUES(brand_id)',
            'brand_name = VALUES(brand_name)',
            'supplier_id = VALUES(supplier_id)',
            'supplier_name = VALUES(supplier_name)',
            'supplier_reference = VALUES(supplier_reference)',
            'customer_id = VALUES(customer_id)',
            'mode_vente = VALUES(mode_vente)',
            'channel_code = VALUES(channel_code)',
            'channel_name = VALUES(channel_name)',
            'quantity = VALUES(quantity)',
            'unit_price_ttc = VALUES(unit_price_ttc)',
            'total_ht = VALUES(total_ht)',
            'total_ttc = VALUES(total_ttc)',
            'margin_ht = VALUES(margin_ht)',
            'discount_percent = VALUES(discount_percent)',
            'discount_amount = VALUES(discount_amount)',
            'tax_rate = VALUES(tax_rate)',
            'raw_origin = VALUES(raw_origin)',
            'raw_type_piece = VALUES(raw_type_piece)',
            'updated_at = VALUES(updated_at)',
        ]);

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
