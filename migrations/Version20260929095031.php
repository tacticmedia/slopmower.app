<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260929095031 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE change_request (id UUID NOT NULL, tenant_id UUID NOT NULL, anchor JSONB NOT NULL, body TEXT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, status VARCHAR(32) NOT NULL, status_before_claim VARCHAR(32) DEFAULT NULL, claimed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, wont_do_set_by VARCHAR(16) DEFAULT NULL, verified_from VARCHAR(32) DEFAULT NULL, last_user_event_seq BIGINT DEFAULT NULL, last_agent_event_seq BIGINT DEFAULT NULL, last_agent_event_asks_question BOOLEAN NOT NULL, awaiting VARCHAR(16) NOT NULL, resolution VARCHAR(16) DEFAULT NULL, last_event_seq BIGINT NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, author_kind VARCHAR(16) NOT NULL, author_user_id UUID DEFAULT NULL, author_agent_name VARCHAR(64) DEFAULT NULL, claimed_by_kind VARCHAR(16) DEFAULT NULL, claimed_by_user_id UUID DEFAULT NULL, claimed_by_agent_name VARCHAR(64) DEFAULT NULL, review_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_change_request_tenant_id ON change_request (tenant_id)');
        $this->addSql('CREATE INDEX IDX_CB902D363E2E969B ON change_request (review_id)');
        $this->addSql('CREATE TABLE comment (id UUID NOT NULL, tenant_id UUID NOT NULL, body TEXT NOT NULL, question BOOLEAN NOT NULL, batch_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, author_kind VARCHAR(16) NOT NULL, author_user_id UUID DEFAULT NULL, author_agent_name VARCHAR(64) DEFAULT NULL, request_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_comment_tenant_id ON comment (tenant_id)');
        $this->addSql('CREATE INDEX IDX_9474526C427EB8A5 ON comment (request_id)');
        $this->addSql('CREATE TABLE commit_link (id UUID NOT NULL, tenant_id UUID NOT NULL, commit_sha VARCHAR(64) NOT NULL, state VARCHAR(16) NOT NULL, linked_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, linked_seq BIGINT NOT NULL, note TEXT DEFAULT NULL, found_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, subject TEXT DEFAULT NULL, committed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, linked_by_kind VARCHAR(16) NOT NULL, linked_by_user_id UUID DEFAULT NULL, linked_by_agent_name VARCHAR(64) DEFAULT NULL, request_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_commit_link_tenant_commit ON commit_link (tenant_id, commit_sha)');
        $this->addSql('CREATE UNIQUE INDEX uniq_commit_link_request_commit ON commit_link (request_id, commit_sha)');
        $this->addSql('CREATE INDEX IDX_47A995A7427EB8A5 ON commit_link (request_id)');
        $this->addSql('CREATE TABLE repository (id UUID NOT NULL, tenant_id UUID NOT NULL, source VARCHAR(16) NOT NULL, name VARCHAR(255) NOT NULL, local_directory VARCHAR(255) DEFAULT NULL, github_repository_id BIGINT DEFAULT NULL, default_branch VARCHAR(255) DEFAULT NULL, default_branch_head VARCHAR(64) DEFAULT NULL, sync_state VARCHAR(16) NOT NULL, last_fetched_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, last_error TEXT DEFAULT NULL, access VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_repository_tenant_source_name ON repository (tenant_id, source, name)');
        $this->addSql('CREATE UNIQUE INDEX uniq_repository_tenant_github_repository_id ON repository (tenant_id, github_repository_id) WHERE (github_repository_id IS NOT NULL)');
        $this->addSql('CREATE TABLE review (id UUID NOT NULL, tenant_id UUID NOT NULL, title VARCHAR(200) NOT NULL, ref VARCHAR(255) NOT NULL, commit_sha VARCHAR(64) NOT NULL, state VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, closed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, close_note TEXT DEFAULT NULL, last_event_seq BIGINT NOT NULL, index_state VARCHAR(16) NOT NULL, files_total INT DEFAULT NULL, files_done INT NOT NULL, symbols_total INT NOT NULL, index_started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, index_finished_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, index_error TEXT DEFAULT NULL, started_by_kind VARCHAR(16) NOT NULL, started_by_user_id UUID DEFAULT NULL, started_by_agent_name VARCHAR(64) DEFAULT NULL, closed_by_kind VARCHAR(16) DEFAULT NULL, closed_by_user_id UUID DEFAULT NULL, closed_by_agent_name VARCHAR(64) DEFAULT NULL, repository_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_review_tenant_id ON review (tenant_id)');
        $this->addSql('CREATE INDEX IDX_794381C650C9D4F7 ON review (repository_id)');
        $this->addSql('CREATE TABLE review_event (id UUID NOT NULL, tenant_id UUID NOT NULL, seq BIGINT NOT NULL, review_id UUID NOT NULL, request_id UUID DEFAULT NULL, request_ids JSONB NOT NULL, type VARCHAR(64) NOT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, payload JSONB NOT NULL, actor_kind VARCHAR(16) NOT NULL, actor_user_id UUID DEFAULT NULL, actor_agent_name VARCHAR(64) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_review_event_tenant_review_seq ON review_event (tenant_id, review_id, seq)');
        $this->addSql('CREATE UNIQUE INDEX uniq_review_event_tenant_seq ON review_event (tenant_id, seq)');
        $this->addSql('ALTER TABLE change_request ADD CONSTRAINT FK_CB902D363E2E969B FOREIGN KEY (review_id) REFERENCES review (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C427EB8A5 FOREIGN KEY (request_id) REFERENCES change_request (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE commit_link ADD CONSTRAINT FK_47A995A7427EB8A5 FOREIGN KEY (request_id) REFERENCES change_request (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE review ADD CONSTRAINT FK_794381C650C9D4F7 FOREIGN KEY (repository_id) REFERENCES repository (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE change_request DROP CONSTRAINT FK_CB902D363E2E969B');
        $this->addSql('ALTER TABLE comment DROP CONSTRAINT FK_9474526C427EB8A5');
        $this->addSql('ALTER TABLE commit_link DROP CONSTRAINT FK_47A995A7427EB8A5');
        $this->addSql('ALTER TABLE review DROP CONSTRAINT FK_794381C650C9D4F7');
        $this->addSql('DROP TABLE change_request');
        $this->addSql('DROP TABLE comment');
        $this->addSql('DROP TABLE commit_link');
        $this->addSql('DROP TABLE repository');
        $this->addSql('DROP TABLE review');
        $this->addSql('DROP TABLE review_event');
    }
}
