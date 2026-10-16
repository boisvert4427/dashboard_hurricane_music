<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link invoice margins to the calculated PAMP stock cost.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE reporting_invoice_line_fact
                ADD source_site_id INT DEFAULT NULL AFTER source_invoice_id,
                ADD source_margin_ht DECIMAL(10,2) DEFAULT NULL AFTER margin_ht,
                ADD pamp_unit_cost DECIMAL(20,6) DEFAULT NULL AFTER source_margin_ht,
                ADD pamp_cost_total DECIMAL(20,6) DEFAULT NULL AFTER pamp_unit_cost,
                ADD KEY idx_invoice_pamp_match (source_invoice_id, product_id, source_site_id)
        SQL);
        $this->addSql('UPDATE reporting_invoice_line_fact SET source_margin_ht = margin_ht WHERE source_margin_ht IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE reporting_invoice_line_fact
                DROP INDEX idx_invoice_pamp_match,
                DROP source_site_id,
                DROP source_margin_ht,
                DROP pamp_unit_cost,
                DROP pamp_cost_total
        SQL);
    }
}
