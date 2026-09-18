<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schedule price retries independently of successful price observations.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competitor_url_final
            ADD last_price_attempt_at DATETIME DEFAULT NULL,
            ADD last_price_result VARCHAR(32) DEFAULT NULL,
            ADD consecutive_price_not_found INT NOT NULL DEFAULT 0,
            ADD next_price_check_at DATETIME DEFAULT NULL,
            ADD price_check_requested_at DATETIME DEFAULT NULL,
            ADD INDEX idx_final_price_due (competitor_id, next_price_check_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competitor_url_final
            DROP INDEX idx_final_price_due,
            DROP last_price_attempt_at,
            DROP last_price_result,
            DROP consecutive_price_not_found,
            DROP next_price_check_at,
            DROP price_check_requested_at');
    }
}
