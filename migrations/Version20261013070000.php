<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261013070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The Marketplace plan of each GitHub account, and the account ID of an installation (F42)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE marketplace_account (account_id BIGINT NOT NULL, plan_id BIGINT NOT NULL, billing_cycle VARCHAR(255) NOT NULL, trial_ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, pending_plan_id BIGINT DEFAULT NULL, pending_effective_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (account_id))');
        $this->addSql('ALTER TABLE github_installation ADD account_id BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE marketplace_account');
        $this->addSql('ALTER TABLE github_installation DROP account_id');
    }
}
