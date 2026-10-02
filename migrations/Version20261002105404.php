<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002105404 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ApiToken';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE api_token (id UUID NOT NULL, tenant_id UUID NOT NULL, name VARCHAR(64) NOT NULL, token_hash VARCHAR(64) NOT NULL, prefix VARCHAR(9) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, repository_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_api_token_tenant ON api_token (tenant_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_api_token_hash ON api_token (token_hash)');
        $this->addSql('CREATE INDEX IDX_7BA2F5EBA76ED395 ON api_token (user_id)');
        $this->addSql('CREATE INDEX IDX_7BA2F5EB50C9D4F7 ON api_token (repository_id)');
        $this->addSql('ALTER TABLE api_token ADD CONSTRAINT FK_7BA2F5EBA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE api_token ADD CONSTRAINT FK_7BA2F5EB50C9D4F7 FOREIGN KEY (repository_id) REFERENCES repository (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_token DROP CONSTRAINT FK_7BA2F5EBA76ED395');
        $this->addSql('ALTER TABLE api_token DROP CONSTRAINT FK_7BA2F5EB50C9D4F7');
        $this->addSql('DROP TABLE api_token');
    }
}
