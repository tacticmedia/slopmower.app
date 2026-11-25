<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125090856 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The requester, the assignee, and waitingFor of an issue, and no status awaiting_user_input (D134)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issue ADD waiting_for VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE issue ADD requester_kind VARCHAR(255)');
        $this->addSql('ALTER TABLE issue ADD requester_user_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE issue ADD assignee_kind VARCHAR(255)');
        $this->addSql('ALTER TABLE issue ADD assignee_user_id UUID DEFAULT NULL');
        $this->addSql("UPDATE issue SET requester_kind = 'user', requester_user_id = created_by_user_id, assignee_kind = 'agent'");
        // The status before awaiting_user_input, as todo where it waits for nobody, so the plan approval of the user hands
        // the issue to the agent in todo. Reads the stored entries, so it runs before they change. Without such an entry: draft.
        $this->addSql(<<<'SQL'
            UPDATE issue SET waiting_for = 'requester', status = COALESCE((
                SELECT CASE WHEN entry.data->>'from' IN ('backlog', 'done', 'wont_do') THEN 'todo' ELSE entry.data->>'from' END FROM issue_entry entry
                WHERE entry.issue_id = issue.id AND entry.kind = 'status_changed' AND entry.data->>'to' = 'awaiting_user_input'
                ORDER BY entry.id DESC LIMIT 1
            ), 'draft')
            WHERE status = 'awaiting_user_input'
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE issue SET waiting_for = CASE status WHEN 'draft' THEN 'requester' WHEN 'in_review' THEN 'requester' WHEN 'todo' THEN 'assignee' WHEN 'in_progress' THEN 'assignee' END
            WHERE waiting_for IS NULL
            SQL);
        // X -> awaiting_user_input is the hand-over X -> X to the requester. awaiting_user_input -> Y is X -> Y with the
        // default party of Y, X from the status change before it. X is todo where the status before waits for nobody.
        $this->addSql(<<<'SQL'
            WITH change AS (
                SELECT id, data->>'from' AS from_status, data->>'to' AS to_status, LAG(data->>'from') OVER (PARTITION BY issue_id ORDER BY id) AS status_before
                FROM issue_entry WHERE kind = 'status_changed'
            ), translated AS (
                SELECT id, from_status, to_status,
                    CASE WHEN from_status IN ('backlog', 'done', 'wont_do') THEN 'todo' ELSE from_status END AS waiting_status,
                    CASE WHEN status_before IN ('backlog', 'done', 'wont_do') THEN 'todo' ELSE COALESCE(status_before, 'draft') END AS waited_status
                FROM change
            )
            UPDATE issue_entry entry SET data = entry.data || CASE
                WHEN translated.to_status = 'awaiting_user_input' THEN jsonb_build_object('to', translated.waiting_status, 'waitingFor', 'requester')
                ELSE jsonb_build_object('from', translated.waited_status, 'waitingFor', CASE translated.to_status WHEN 'draft' THEN 'requester' WHEN 'in_review' THEN 'requester' WHEN 'todo' THEN 'assignee' WHEN 'in_progress' THEN 'assignee' END)
            END
            FROM translated
            WHERE entry.id = translated.id AND 'awaiting_user_input' IN (translated.from_status, translated.to_status)
            SQL);
        $this->addSql('ALTER TABLE issue ALTER requester_kind SET NOT NULL');
        $this->addSql('ALTER TABLE issue ALTER assignee_kind SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The rewritten status_changed entries do not say which ones named awaiting_user_input.');
    }
}
