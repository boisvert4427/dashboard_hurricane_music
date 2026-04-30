<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260429103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the final competitor URL table for validated matches.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE competitor_url_final (
                id INT AUTO_INCREMENT NOT NULL,
                competitor_id INT NOT NULL,
                url VARCHAR(2048) NOT NULL,
                INDEX idx_final_competitor (competitor_id),
                UNIQUE INDEX uk_final_competitor_url (competitor_id, url),
                PRIMARY KEY(id),
                CONSTRAINT FK_FINAL_COMPETITOR FOREIGN KEY (competitor_id) REFERENCES competitor (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE competitor_url_final');
    }
}
