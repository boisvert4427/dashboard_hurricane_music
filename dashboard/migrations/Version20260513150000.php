<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track repeated final-price HTTP failures on competitor_url_final.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competitor_url_final ADD last_http_status SMALLINT DEFAULT NULL AFTER competitor_price');
        $this->addSql('ALTER TABLE competitor_url_final ADD consecutive_http_failures SMALLINT NOT NULL DEFAULT 0 AFTER last_http_status');
        $this->addSql('ALTER TABLE competitor_url_final ADD last_http_error_at DATETIME DEFAULT NULL AFTER consecutive_http_failures');
        $this->addSql('ALTER TABLE competitor_url_final ADD last_http_error_message VARCHAR(255) DEFAULT NULL AFTER last_http_error_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competitor_url_final DROP last_http_error_message');
        $this->addSql('ALTER TABLE competitor_url_final DROP last_http_error_at');
        $this->addSql('ALTER TABLE competitor_url_final DROP consecutive_http_failures');
        $this->addSql('ALTER TABLE competitor_url_final DROP last_http_status');
    }
}
