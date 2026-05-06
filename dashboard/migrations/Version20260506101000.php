<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506101000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add validation status to competitor_url_test_result and create competitor_url_rejected_url.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competitor_url_test_result ADD validation_status VARCHAR(16) NOT NULL DEFAULT \'pending\'');
        $this->addSql('UPDATE competitor_url_test_result SET validation_status = \'pending\' WHERE validation_status IS NULL OR validation_status = \'\'');
        $this->addSql(<<<'SQL'
            CREATE TABLE competitor_url_rejected_url (
                id INT AUTO_INCREMENT NOT NULL,
                competitor_id INT NOT NULL,
                url VARCHAR(2048) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uk_rejected_competitor_url (competitor_id, url),
                INDEX idx_rejected_competitor (competitor_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_REJECTED_URL_COMPETITOR FOREIGN KEY (competitor_id) REFERENCES competitor (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE competitor_url_rejected_url');
        $this->addSql('ALTER TABLE competitor_url_test_result DROP validation_status');
    }
}
