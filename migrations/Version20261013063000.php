<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261013063000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The check run of a pull request record and the check of every pull request (F41)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pull_request ADD check_run_requested BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE pull_request ADD check_run_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE pull_request ADD check_run_head_sha VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE pull_request ADD check_run_hash VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE pull_request ADD check_run_created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE repository ADD check_every_pull_request BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pull_request DROP check_run_requested');
        $this->addSql('ALTER TABLE pull_request DROP check_run_id');
        $this->addSql('ALTER TABLE pull_request DROP check_run_head_sha');
        $this->addSql('ALTER TABLE pull_request DROP check_run_hash');
        $this->addSql('ALTER TABLE pull_request DROP check_run_created_at');
        $this->addSql('ALTER TABLE repository DROP check_every_pull_request');
    }
}
