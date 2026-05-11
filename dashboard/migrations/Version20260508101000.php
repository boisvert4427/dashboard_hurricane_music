<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508101000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add competitor image URL to competitor_url_test_result.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competitor_url_test_result ADD competitor_image_url VARCHAR(2048) DEFAULT NULL AFTER competitor_brand');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competitor_url_test_result DROP competitor_image_url');
    }
}
