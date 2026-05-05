<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove title column from competitor_url_test_result and keep competitor_title only.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competitor_url_test_result DROP COLUMN title');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competitor_url_test_result ADD title VARCHAR(255) DEFAULT NULL');
    }
}
