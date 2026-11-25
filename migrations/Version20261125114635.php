<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125114635 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The rank and the last activity of an issue (D137)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issue ADD rank INT');
        $this->addSql('UPDATE issue SET rank = number');
        $this->addSql('ALTER TABLE issue ALTER rank SET NOT NULL');
        $this->addSql('ALTER TABLE issue ADD last_activity_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // The entry of the problem of a new issue has the time of the creation, so it is no activity.
        $this->addSql('UPDATE issue SET last_activity_at = (SELECT MAX(entry.created_at) FROM issue_entry entry WHERE entry.issue_id = issue.id AND entry.created_at > issue.created_at)');
        $this->addSql('CREATE INDEX idx_issue_rank ON issue (project_id, category, rank)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_issue_rank');
        $this->addSql('ALTER TABLE issue DROP rank');
        $this->addSql('ALTER TABLE issue DROP last_activity_at');
    }
}
