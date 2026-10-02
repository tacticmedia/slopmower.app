<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002182906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Location cache and the outdated flag of requests';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE location_cache_entry (tenant_id UUID NOT NULL, opened_event_id UUID NOT NULL, target_sha VARCHAR(64) NOT NULL, repository_id UUID NOT NULL, status VARCHAR(255) NOT NULL, path TEXT DEFAULT NULL, start_line INT DEFAULT NULL, end_line INT DEFAULT NULL, confidence DOUBLE PRECISION NOT NULL, reason VARCHAR(255) DEFAULT NULL, PRIMARY KEY (tenant_id, opened_event_id, target_sha))');
        $this->addSql('CREATE INDEX idx_location_cache_entry_repository ON location_cache_entry (tenant_id, repository_id)');
        $this->addSql('ALTER TABLE change_request ADD outdated BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE location_cache_entry');
        $this->addSql('ALTER TABLE change_request DROP outdated');
    }
}
