<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260429143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the last competitor test result table keyed by PrestaShop product id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE competitor_url_test_result (
                id_product INT NOT NULL,
                competitor_id INT NOT NULL,
                result VARCHAR(16) NOT NULL,
                url VARCHAR(2048) DEFAULT NULL,
                title VARCHAR(255) DEFAULT NULL,
                score SMALLINT DEFAULT NULL,
                matched_query VARCHAR(255) DEFAULT NULL,
                message VARCHAR(255) DEFAULT NULL,
                last_tested_at DATETIME NOT NULL,
                INDEX idx_test_result_competitor (competitor_id),
                INDEX idx_test_result_status (result),
                PRIMARY KEY(id_product, competitor_id),
                CONSTRAINT FK_TEST_RESULT_COMPETITOR FOREIGN KEY (competitor_id) REFERENCES competitor (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE competitor_url_test_result');
    }
}
