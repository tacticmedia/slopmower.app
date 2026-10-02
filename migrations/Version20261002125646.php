<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002125646 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'GitHub installations and candidates';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE github_candidate (id UUID NOT NULL, tenant_id UUID NOT NULL, github_repository_id BIGINT NOT NULL, installation_id BIGINT NOT NULL, account_login VARCHAR(255) NOT NULL, owner VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, private BOOLEAN NOT NULL, default_branch VARCHAR(255) NOT NULL, html_url TEXT NOT NULL, listed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_github_candidate ON github_candidate (tenant_id, github_repository_id)');
        $this->addSql('CREATE TABLE github_installation (id UUID NOT NULL, tenant_id UUID NOT NULL, installation_id BIGINT NOT NULL, account_login VARCHAR(255) NOT NULL, account_type VARCHAR(255) NOT NULL, suspended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_github_installation ON github_installation (tenant_id, installation_id)');
        $this->addSql('ALTER TABLE repository ADD github_installation_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE repository ADD github_private BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE github_candidate');
        $this->addSql('DROP TABLE github_installation');
        $this->addSql('ALTER TABLE repository DROP github_installation_id');
        $this->addSql('ALTER TABLE repository DROP github_private');
    }
}
