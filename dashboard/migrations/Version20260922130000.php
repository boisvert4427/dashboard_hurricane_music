<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add independent FIFO and recalculated PAMP stock movement cache.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE reporting_stock_movement_cost (
                source_stock_history_id INT NOT NULL,
                movement_date DATETIME NOT NULL,
                product_id INT NOT NULL,
                site_id INT NOT NULL,
                source_piece_id INT NOT NULL,
                nature VARCHAR(3) NOT NULL,
                piece VARCHAR(255) NOT NULL,
                quantity_delta DECIMAL(14,3) NOT NULL,
                source_stock_after DECIMAL(14,3) NOT NULL,
                source_last_purchase_price DECIMAL(20,6) DEFAULT NULL,
                reception_unit_cost DECIMAL(20,6) DEFAULT NULL,
                incoming_unit_cost DECIMAL(20,6) DEFAULT NULL,
                calculated_pamp_before DECIMAL(20,6) NOT NULL DEFAULT 0,
                calculated_pamp_after DECIMAL(20,6) NOT NULL DEFAULT 0,
                pamp_cost_total DECIMAL(20,6) NOT NULL DEFAULT 0,
                fifo_unit_cost DECIMAL(20,6) DEFAULT NULL,
                fifo_cost_total DECIMAL(20,6) DEFAULT NULL,
                calculated_quantity_after DECIMAL(14,3) NOT NULL DEFAULT 0,
                pamp_stock_value DECIMAL(20,6) NOT NULL DEFAULT 0,
                fifo_stock_value DECIMAL(20,6) NOT NULL DEFAULT 0,
                calculation_status VARCHAR(255) NOT NULL DEFAULT 'ok',
                calculated_at DATETIME NOT NULL,
                PRIMARY KEY (source_stock_history_id),
                KEY idx_stock_cost_product_site_date (product_id, site_id, movement_date),
                KEY idx_stock_cost_date (movement_date),
                KEY idx_stock_cost_piece (source_piece_id, product_id, site_id),
                KEY idx_stock_cost_nature (nature)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE reporting_stock_current_cost (
                product_id INT NOT NULL,
                site_id INT NOT NULL,
                quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
                calculated_pamp DECIMAL(20,6) NOT NULL DEFAULT 0,
                pamp_stock_value DECIMAL(20,6) NOT NULL DEFAULT 0,
                fifo_stock_value DECIMAL(20,6) NOT NULL DEFAULT 0,
                last_source_stock_history_id INT NOT NULL,
                last_movement_date DATETIME DEFAULT NULL,
                calculated_at DATETIME NOT NULL,
                PRIMARY KEY (product_id, site_id),
                KEY idx_stock_current_site (site_id),
                KEY idx_stock_current_movement_date (last_movement_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE reporting_stock_recalc_queue (
                product_id INT NOT NULL,
                site_id INT NOT NULL,
                first_changed_source_id INT NOT NULL,
                queued_at DATETIME NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                last_error TEXT DEFAULT NULL,
                PRIMARY KEY (product_id, site_id),
                KEY idx_stock_queue_queued_at (queued_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE reporting_etl_checkpoint (
                process_name VARCHAR(100) NOT NULL,
                checkpoint_value BIGINT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (process_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reporting_stock_movement_cost');
        $this->addSql('DROP TABLE reporting_stock_current_cost');
        $this->addSql('DROP TABLE reporting_stock_recalc_queue');
        $this->addSql('DROP TABLE reporting_etl_checkpoint');
    }
}
