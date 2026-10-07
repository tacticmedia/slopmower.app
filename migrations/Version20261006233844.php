<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261006233844 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'GIN index of review_event.request_ids for the thread reads';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_review_event_request_ids ON review_event USING GIN (request_ids jsonb_path_ops)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_review_event_request_ids');
    }
}
