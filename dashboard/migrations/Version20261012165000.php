<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012165000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Speed up the latest competitor price lookup used by price batches.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE INDEX idx_price_history_competitor_product_url_observed '
            . 'ON competitor_url_price_history (competitor_id, id_product, url(500), observed_at)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_price_history_competitor_product_url_observed ON competitor_url_price_history');
    }
}
