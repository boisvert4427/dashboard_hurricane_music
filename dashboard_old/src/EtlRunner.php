<?php

declare(strict_types=1);

namespace Dashboard;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class EtlRunner
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{inserted:int, source_rows:int, last_id:int}
     */
    public function run(): array
    {
        $schemaPath = dirname(__DIR__) . '/sql/create_reporting_invoice_line_fact.sql';

        if (!is_file($schemaPath)) {
            throw new RuntimeException('Schema file not found: ' . $schemaPath);
        }

        $schemaSql = (string) file_get_contents($schemaPath);
        if ($schemaSql === '') {
            throw new RuntimeException('Schema file is empty: ' . $schemaPath);
        }

        $this->pdo->exec($schemaSql);

        $tableExists = $this->pdo->query("SHOW TABLES LIKE 'K_Li_FAC'")?->fetchColumn() !== false;
        if (!$tableExists) {
            throw new RuntimeException('Source table K_Li_FAC not found.');
        }

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $batchSize = 500;
        $lastId = 0;
        $inserted = 0;
        $sourceRows = 0;

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM reporting_invoice_line_fact');

            while (true) {
                $statement = $this->pdo->prepare(
                    <<<'SQL'
                        SELECT
                            IDLigneFac,
                            IDFAC,
                            NumFacPoste,
                            IDART,
                            CODE,
                            DESIGNATION_PRODUIT,
                            IDCLI,
                            IDVENDEUR,
                            IDPRESTATAIRE,
                            IDREP,
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
                        FROM K_Li_FAC
                        WHERE IDLigneFac > :last_id
                        ORDER BY IDLigneFac ASC
                        LIMIT :batch_size
                    SQL
                );
                $statement->bindValue('last_id', $lastId, PDO::PARAM_INT);
                $statement->bindValue('batch_size', $batchSize, PDO::PARAM_INT);
                $statement->execute();
                $rows = $statement->fetchAll();

                if ($rows === []) {
                    break;
                }

                $payload = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $payload[] = $this->mapRow($row, $now);
                    $lastId = max($lastId, (int) ($row['IDLigneFac'] ?? 0));
                }

                $this->insertBatch($payload);
                $sourceRows += count($payload);
                $inserted += count($payload);

                if (count($rows) < $batchSize) {
                    break;
                }
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return [
            'inserted' => $inserted,
            'source_rows' => $sourceRows,
            'last_id' => $lastId,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, mixed>
     */
    private function mapRow(array $row, string $now): array
    {
        [$invoiceDate, $invoiceDateTime] = $this->resolveInvoiceTimestamps($row);
        [$channelCode, $channelName] = $this->resolveChannel($row);

        return [
            (int) ($row['IDLigneFac'] ?? 0),
            (int) ($row['IDFAC'] ?? 0),
            $this->normalizeInvoiceNumber($row),
            $invoiceDate,
            $invoiceDateTime,
            (int) ($row['IDART'] ?? 0),
            $this->value($row['CODE'] ?? ''),
            $this->value($row['DESIGNATION_PRODUIT'] ?? ''),
            $this->intOrNull($row['IDCLI'] ?? null),
            $this->intOrNull($row['IDVENDEUR'] ?? null),
            $this->intOrNull($row['IDPRESTATAIRE'] ?? null),
            $this->intOrNull($row['IDREP'] ?? null),
            $this->intOrNull($row['SITE'] ?? null),
            $this->value($row['MODE_VENTE'] ?? ''),
            $channelCode,
            $channelName,
            (int) ($row['WEB'] ?? 0),
            $this->intOrNull($row['NO_WEB'] ?? null),
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
     * @param array<int, array<int, mixed>> $rows
     */
    private function insertBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columns = [
            'source_line_id',
            'source_invoice_id',
            'invoice_number',
            'invoice_date',
            'invoice_datetime',
            'product_id',
            'product_code',
            'product_name',
            'customer_id',
            'seller_id',
            'prestataire_id',
            'representative_id',
            'site_id',
            'mode_vente',
            'channel_code',
            'channel_name',
            'web_flag',
            'no_web_flag',
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

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
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
            } catch (Throwable) {
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
    private function resolveChannel(array $row): array
    {
        $mode = strtoupper($this->value($row['MODE_VENTE'] ?? ''));
        $webFlag = (int) ($row['WEB'] ?? 0);
        $noWebFlag = (int) ($row['NO_WEB'] ?? 0);

        if ($mode !== '') {
            $code = preg_replace('/[^A-Z0-9]+/', '_', $mode) ?: 'AUTRE';

            if (str_contains($mode, 'WEB')) {
                return ['WEB', 'Web'];
            }

            if (str_contains($mode, 'BOUT') || str_contains($mode, 'MAG') || str_contains($mode, 'STORE') || str_contains($mode, 'POS')) {
                return [$code, 'Boutique'];
            }

            if (str_contains($mode, 'B2B') || str_contains($mode, 'PRO')) {
                return [$code, 'B2B'];
            }

            if (str_contains($mode, 'REP') || str_contains($mode, 'COMMERCIAL')) {
                return [$code, 'Representant'];
            }

            return [$code, ucwords(strtolower(str_replace(['_', '-'], ' ', $mode)))];
        }

        if ($webFlag === 1) {
            return ['WEB', 'Web'];
        }

        if ($noWebFlag === 1) {
            return ['NO_WEB', 'Hors web'];
        }

        return ['AUTRE', 'Autre'];
    }

    private function normalizeInvoiceNumber(array $row): string
    {
        $invoiceNumber = $this->value($row['NumFacPoste'] ?? '');

        if ($invoiceNumber !== '') {
            return $invoiceNumber;
        }

        return (string) ((int) ($row['IDFAC'] ?? 0));
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
        } catch (Throwable) {
            return null;
        }
    }
}
