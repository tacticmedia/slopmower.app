<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\IssueCommands;
use App\Application\Query\IssueQuery;
use App\Application\Query\IssueView;
use App\Domain\Issue\IssueKey;
use App\Domain\Issue\IssueStatus;
use App\Domain\Issue\WaitingFor;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.24: REST I8.
 */
#[McpTool(
    name: 'set_issue_status',
    title: 'Set issue status',
    description: 'Move an issue to another status, or hand it to the other party in the status that it has. The server checks the state that the move gives: backlog, todo, in_progress, in_review, approved, and done need problem; in_progress, in_review, approved, and done need a plan that is approved as approvalMode says; in_review, approved, and done need result; wont_do needs note. approved, done, and wont_do from an agent: refused in approvalMode user, with reviewer and note in agent_review, free in self. done from approved is free in every mode. in_review: the work is ready and not merged, and waits for the review of the requester. approved: the requester accepted it; merge it, write the commit on the default branch into result, and set done. waitingFor is the party with the next step: by default the requester in draft and in_review, the assignee in todo, in_progress, and approved, nobody in backlog, done, and wont_do. When the user must decide, hand the issue to the requester: send the status that it has with waitingFor requester. allowedStatuses does not list that status, and the hand-over is allowed. Take an issue back from a user only after the user answered in the session, and record the answer as a decision first. The move holds the issue for your session for 2 hours. A move to backlog, in_review, done, or wont_do, out of in_progress, or to a user ends the claim. To give up an issue in work, set todo with a note. A refusal is invalid_input on the field that breaks the rule: on session while another session holds the issue, so take another issue. allowedStatuses of the issue lists the other statuses that you can set now.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    outputSchema: BundledSchemas::ISSUE,
)]
final readonly class SetIssueStatusTool
{
    public function __construct(
        private ToolCall $toolCall,
        private IssueCommands $issueCommands,
        private IssueQuery $issues,
        private CurrentActor $currentActor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'Issue key, for example SM-12', pattern: Input::ISSUE_KEY)]
        string $issue,
        #[Schema(description: Input::SESSION, minLength: 1, maxLength: 64, pattern: Input::NO_NUL)]
        string $session,
        #[Schema(description: 'The new status')]
        IssueStatus $status,
        #[Schema(description: 'requester or assignee: who has the next step after the move. Null: the default of the status, or in the status that the issue has, the party that it waits for')]
        ?WaitingFor $waitingFor = null,
        #[Schema(description: 'Markdown: why, for the thread. Required for wont_do, and in approvalMode agent_review for approved, wont_do, and done from another status than approved', minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        ?string $note = null,
        #[Schema(description: 'Name of the agent or subagent that reviewed the result. Required in approvalMode agent_review for approved, wont_do, and done from another status than approved', minLength: 1, maxLength: 64, pattern: Input::NO_NUL)]
        ?string $reviewer = null,
    ): array {
        return $this->toolCall->run('set_issue_status', function () use ($issue, $session, $status, $waitingFor, $note, $reviewer): IssueView {
            $actor = $this->currentActor->get();

            return $this->issues->view($this->issueCommands->setStatus(IssueKey::fromString($issue, 'issue'), $status, $note, $reviewer, $actor, $session, $waitingFor), $actor);
        });
    }
}
