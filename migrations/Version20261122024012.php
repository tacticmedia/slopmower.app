<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122024012 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The issue tracker: project, issue, issue_entry, issue_link, and the project of a repository (F126, D116)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE issue (id UUID NOT NULL, tenant_id UUID NOT NULL, number INT NOT NULL, title VARCHAR(200) NOT NULL, status VARCHAR(255) NOT NULL, problem TEXT DEFAULT NULL, plan TEXT DEFAULT NULL, result TEXT DEFAULT NULL, follow_ups TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by_kind VARCHAR(255) DEFAULT NULL, created_by_user_id UUID DEFAULT NULL, created_by_agent_name VARCHAR(64) DEFAULT NULL, project_id UUID NOT NULL, plan_approval_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_issue_number ON issue (project_id, number)');
        $this->addSql('CREATE INDEX IDX_12AD233E166D1F9C ON issue (project_id)');
        $this->addSql('CREATE INDEX IDX_12AD233EADC286B ON issue (plan_approval_id)');
        $this->addSql('CREATE TABLE issue_entry (id UUID NOT NULL, tenant_id UUID NOT NULL, kind VARCHAR(255) NOT NULL, body TEXT DEFAULT NULL, data JSONB NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, actor_kind VARCHAR(255) DEFAULT NULL, actor_user_id UUID DEFAULT NULL, actor_agent_name VARCHAR(64) DEFAULT NULL, issue_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B9ABF4235E7AA58C ON issue_entry (issue_id)');
        $this->addSql('CREATE TABLE issue_link (tenant_id UUID NOT NULL, note TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by_kind VARCHAR(255) DEFAULT NULL, created_by_user_id UUID DEFAULT NULL, created_by_agent_name VARCHAR(64) DEFAULT NULL, issue_id UUID NOT NULL, target_id UUID NOT NULL, PRIMARY KEY (issue_id, target_id))');
        $this->addSql('CREATE INDEX IDX_6E4B513D5E7AA58C ON issue_link (issue_id)');
        $this->addSql('CREATE INDEX IDX_6E4B513D158E0B66 ON issue_link (target_id)');
        $this->addSql('CREATE TABLE project (id UUID NOT NULL, tenant_id UUID NOT NULL, key VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL, approval_mode VARCHAR(255) NOT NULL, next_issue_number INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_key ON project (tenant_id, key)');
        $this->addSql('ALTER TABLE issue ADD CONSTRAINT FK_12AD233E166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE issue ADD CONSTRAINT FK_12AD233EADC286B FOREIGN KEY (plan_approval_id) REFERENCES issue_entry (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE issue_entry ADD CONSTRAINT FK_B9ABF4235E7AA58C FOREIGN KEY (issue_id) REFERENCES issue (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE issue_link ADD CONSTRAINT FK_6E4B513D5E7AA58C FOREIGN KEY (issue_id) REFERENCES issue (id)');
        $this->addSql('ALTER TABLE issue_link ADD CONSTRAINT FK_6E4B513D158E0B66 FOREIGN KEY (target_id) REFERENCES issue (id)');
        $this->addSql('ALTER TABLE repository ADD project_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE repository ADD CONSTRAINT FK_5CFE57CD166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('CREATE INDEX IDX_5CFE57CD166D1F9C ON repository (project_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issue DROP CONSTRAINT FK_12AD233E166D1F9C');
        $this->addSql('ALTER TABLE issue DROP CONSTRAINT FK_12AD233EADC286B');
        $this->addSql('ALTER TABLE issue_entry DROP CONSTRAINT FK_B9ABF4235E7AA58C');
        $this->addSql('ALTER TABLE issue_link DROP CONSTRAINT FK_6E4B513D5E7AA58C');
        $this->addSql('ALTER TABLE issue_link DROP CONSTRAINT FK_6E4B513D158E0B66');
        $this->addSql('ALTER TABLE repository DROP CONSTRAINT FK_5CFE57CD166D1F9C');
        $this->addSql('DROP INDEX IDX_5CFE57CD166D1F9C');
        $this->addSql('ALTER TABLE repository DROP project_id');
        $this->addSql('DROP TABLE issue');
        $this->addSql('DROP TABLE issue_entry');
        $this->addSql('DROP TABLE issue_link');
        $this->addSql('DROP TABLE project');
    }
}
