<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260429160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Increase competitor_url_test_result.result length for cloudflare/search_input_not_found statuses.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competitor_url_test_result CHANGE result result VARCHAR(32) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competitor_url_test_result CHANGE result result VARCHAR(16) NOT NULL');
    }
}
