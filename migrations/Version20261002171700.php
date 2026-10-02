<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002171700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drafts and viewed files';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE draft (id UUID NOT NULL, tenant_id UUID NOT NULL, kind VARCHAR(255) NOT NULL, anchor JSONB DEFAULT NULL, body TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, review_id UUID NOT NULL, request_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_draft_review_user ON draft (review_id, user_id)');
        $this->addSql('CREATE INDEX IDX_467C9694A76ED395 ON draft (user_id)');
        $this->addSql('CREATE INDEX IDX_467C96943E2E969B ON draft (review_id)');
        $this->addSql('CREATE INDEX IDX_467C9694427EB8A5 ON draft (request_id)');
        $this->addSql('CREATE TABLE viewed_file (id UUID NOT NULL, tenant_id UUID NOT NULL, path TEXT NOT NULL, path_hash VARCHAR(64) NOT NULL, user_id UUID NOT NULL, review_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_viewed_file ON viewed_file (review_id, user_id, path_hash)');
        $this->addSql('CREATE INDEX IDX_BDF0C59CA76ED395 ON viewed_file (user_id)');
        $this->addSql('CREATE INDEX IDX_BDF0C59C3E2E969B ON viewed_file (review_id)');
        $this->addSql('ALTER TABLE draft ADD CONSTRAINT FK_467C9694A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE draft ADD CONSTRAINT FK_467C96943E2E969B FOREIGN KEY (review_id) REFERENCES review (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE draft ADD CONSTRAINT FK_467C9694427EB8A5 FOREIGN KEY (request_id) REFERENCES change_request (id)');
        $this->addSql('ALTER TABLE viewed_file ADD CONSTRAINT FK_BDF0C59CA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE viewed_file ADD CONSTRAINT FK_BDF0C59C3E2E969B FOREIGN KEY (review_id) REFERENCES review (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE draft DROP CONSTRAINT FK_467C9694A76ED395');
        $this->addSql('ALTER TABLE draft DROP CONSTRAINT FK_467C96943E2E969B');
        $this->addSql('ALTER TABLE draft DROP CONSTRAINT FK_467C9694427EB8A5');
        $this->addSql('ALTER TABLE viewed_file DROP CONSTRAINT FK_BDF0C59CA76ED395');
        $this->addSql('ALTER TABLE viewed_file DROP CONSTRAINT FK_BDF0C59C3E2E969B');
        $this->addSql('DROP TABLE draft');
        $this->addSql('DROP TABLE viewed_file');
    }
}
