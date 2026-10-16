<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the current product dimension synchronized from K_ARTICLE.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE reporting_product_dimension (
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

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reporting_product_dimension');
    }
}
