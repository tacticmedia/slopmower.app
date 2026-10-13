<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261013035814 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pull request state and the accepted permissions of an installation (D64)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pull_request (id UUID NOT NULL, tenant_id UUID NOT NULL, number INT NOT NULL, title TEXT DEFAULT NULL, state VARCHAR(255) DEFAULT NULL, merged BOOLEAN NOT NULL, draft BOOLEAN NOT NULL, head_sha VARCHAR(64) DEFAULT NULL, head_repository_full_name VARCHAR(255) DEFAULT NULL, github_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, repository_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_pull_request ON pull_request (tenant_id, repository_id, number)');
        $this->addSql('CREATE INDEX IDX_8B9B9EEF50C9D4F7 ON pull_request (repository_id)');
        $this->addSql('ALTER TABLE pull_request ADD CONSTRAINT FK_8B9B9EEF50C9D4F7 FOREIGN KEY (repository_id) REFERENCES repository (id) NOT DEFERRABLE');
        // The next sign-in or installation delivery records the accepted permissions.
        $this->addSql("ALTER TABLE github_installation ADD permissions JSONB NOT NULL DEFAULT '{}'");
        $this->addSql('ALTER TABLE github_installation ALTER permissions DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pull_request DROP CONSTRAINT FK_8B9B9EEF50C9D4F7');
        $this->addSql('DROP TABLE pull_request');
        $this->addSql('ALTER TABLE github_installation DROP permissions');
    }
}
