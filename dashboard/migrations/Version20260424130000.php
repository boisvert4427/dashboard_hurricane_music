<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260424130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create a lean reporting invoice line fact table for ETL and dashboard use.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
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
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reporting_invoice_line_fact');
    }
}
