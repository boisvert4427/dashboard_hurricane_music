<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428141500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create competitors and URL candidates tables for the competitive intelligence module.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE competitor (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(255) NOT NULL,
                domain VARCHAR(255) NOT NULL,
                search_url_pattern VARCHAR(255) NOT NULL,
                UNIQUE INDEX uk_competitor_domain (domain),
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE competitor_url_candidate (
                id INT AUTO_INCREMENT NOT NULL,
                competitor_id INT NOT NULL,
                id_product INT NOT NULL,
                url VARCHAR(2048) NOT NULL,
                title VARCHAR(255) DEFAULT NULL,
                source VARCHAR(50) DEFAULT NULL,
                score SMALLINT NOT NULL DEFAULT 0,
                status ENUM('pending', 'valid', 'rejected') NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_candidate_product (id_product),
                INDEX idx_candidate_competitor (competitor_id),
                INDEX idx_candidate_status (status),
                INDEX idx_candidate_score (score),
                INDEX idx_candidate_url (url(191)),
                PRIMARY KEY(id),
                CONSTRAINT FK_CANDIDATE_COMPETITOR FOREIGN KEY (competitor_id) REFERENCES competitor (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE competitor_url_candidate');
        $this->addSql('DROP TABLE competitor');
    }
}
