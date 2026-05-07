<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add current competitor price on finals and append-only price history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competitor_url_final ADD competitor_price DECIMAL(10,2) DEFAULT NULL AFTER url');
        $this->addSql('ALTER TABLE competitor_url_final ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER competitor_price');
        $this->addSql('ALTER TABLE competitor_url_final ADD updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
        $this->addSql(<<<'SQL'
            CREATE TABLE competitor_url_price_history (
                id INT AUTO_INCREMENT NOT NULL,
                competitor_id INT NOT NULL,
                id_product INT NOT NULL,
                url VARCHAR(2048) NOT NULL,
                price DECIMAL(10,2) NOT NULL,
                source VARCHAR(32) DEFAULT NULL,
                observed_at DATETIME NOT NULL,
                INDEX idx_price_history_competitor (competitor_id),
                INDEX idx_price_history_product (id_product),
                INDEX idx_price_history_observed_at (observed_at),
                PRIMARY KEY(id),
                CONSTRAINT FK_PRICE_HISTORY_COMPETITOR FOREIGN KEY (competitor_id) REFERENCES competitor (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE competitor_url_final f
            LEFT JOIN competitor_url_test_result tr
              ON tr.id_product = f.id AND tr.competitor_id = f.competitor_id
            SET f.created_at = COALESCE(tr.last_tested_at, f.created_at),
                f.updated_at = COALESCE(tr.last_tested_at, f.updated_at)
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE competitor_url_final f
            SET f.competitor_price = (
                SELECT tr.competitor_price
                FROM competitor_url_test_result tr
                WHERE tr.id_product = f.id
                  AND tr.competitor_id = f.competitor_id
                  AND tr.competitor_price IS NOT NULL
                ORDER BY tr.last_tested_at DESC
                LIMIT 1
            )
            WHERE EXISTS (
                SELECT 1
                FROM competitor_url_test_result tr2
                WHERE tr2.id_product = f.id
                  AND tr2.competitor_id = f.competitor_id
                  AND tr2.competitor_price IS NOT NULL
            )
        SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO competitor_url_price_history (competitor_id, id_product, url, price, source, observed_at)
            SELECT f.competitor_id, f.id, f.url, f.competitor_price, 'backfill', NOW()
            FROM competitor_url_final f
            WHERE f.competitor_price IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE competitor_url_price_history');
        $this->addSql('ALTER TABLE competitor_url_final DROP updated_at');
        $this->addSql('ALTER TABLE competitor_url_final DROP created_at');
        $this->addSql('ALTER TABLE competitor_url_final DROP competitor_price');
    }
}
