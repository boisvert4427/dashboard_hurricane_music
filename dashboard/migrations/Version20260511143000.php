<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add competitor_page_status to competitor_url_test_result.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE competitor_url_test_result ADD competitor_page_status VARCHAR(16) NOT NULL DEFAULT 'ok' AFTER competitor_image_url");
        $this->addSql("UPDATE competitor_url_test_result SET competitor_page_status = 'ok' WHERE competitor_page_status IS NULL OR competitor_page_status = ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competitor_url_test_result DROP competitor_page_status');
    }
}
