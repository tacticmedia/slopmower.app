<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\IssueCommands;
use App\Application\Query\IssueQuery;
use App\Application\Query\IssueView;
use App\Domain\Issue\ApprovalMode;
use App\Domain\Issue\IssueCategory;
use App\Domain\Issue\IssueKey;
use App\Domain\Issue\IssuePartyKind;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.23: REST I7.
 */
#[McpTool(
    name: 'update_issue',
    title: 'Update issue',
    description: 'Change the title, the category, or the sections of an issue. Null leaves a field as it is; give at least one field. A section is Markdown. problem, acceptanceCriteria, plan, and result are for the user, at most 1,500 characters each: research and steps go into notes. problem: for a bug, what is wrong and how to see it; for a change, what is wanted; no solution and no Done when; the current understanding: write a new version when an answer, a review, or a finding changes it. acceptanceCriteria: how to tell that the issue is done, as outcomes that the user can see, not a mechanism; for a bug, what is expected; a change keeps the plan approval. plan: what changes, one line each; out of scope; risk; no files, tests, or sources. result: what changed, where the work is, and each deviation from the plan. followUps: what came up and was left out. notes: the working memory of the agents: research with sources, steps with files, tests, progress; a change keeps the plan approval; write it at milestones and before a hand-over. A plan with new text clears its approval. An agent cannot change plan or acceptanceCriteria in in_progress, in_review, or approved, and no field of an issue in done or wont_do. approval exists only in approvalMode by_issue, and an agent changes it only to user, and with the agent as the requester also sends requester user. requester and assignee change without an entry, and the issue then waits for the new party of its waitingFor. The write holds the issue for your session for 2 hours. While another session holds it, the write is refused on session: take another issue. Write technical English, as short as possible, and only what a later reader needs.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    outputSchema: BundledSchemas::ISSUE,
)]
final readonly class UpdateIssueTool
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
        #[Schema(description: 'One line that names the issue', minLength: 1, maxLength: 200, pattern: Input::NO_NUL)]
        ?string $title = null,
        #[Schema(description: 'bug: something that exists is wrong. change: all else')]
        ?IssueCategory $category = null,
        #[Schema(description: Input::PROBLEM, minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        ?string $problem = null,
        #[Schema(description: Input::ACCEPTANCE_CRITERIA, minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        ?string $acceptanceCriteria = null,
        #[Schema(description: 'Markdown, for the user: what changes, one line each; out of scope; risk. No files, tests, or sources. At most 1,500 characters', minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        ?string $plan = null,
        #[Schema(description: 'Markdown, for the user: what changed, where the work is (the branch, after the merge the commits), and each deviation from the plan. At most 1,500 characters', minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        ?string $result = null,
        #[Schema(description: 'Markdown: what came up and was left out', minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        ?string $followUps = null,
        #[Schema(description: 'Markdown, the working memory of the agents: research with sources, steps with files, tests, progress. A change keeps the plan approval. Write it at milestones and before a hand-over', minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        ?string $notes = null,
        #[Schema(description: 'Only in approvalMode by_issue: who approves the plan and ends this issue. An agent changes it only to user', enum: ['user', 'agent_review', 'self', null])]
        ?ApprovalMode $approval = null,
        #[Schema(description: 'user or agent. Null keeps it')]
        ?IssuePartyKind $requester = null,
        #[Schema(description: 'user or agent. Null keeps it')]
        ?IssuePartyKind $assignee = null,
    ): array {
        return $this->toolCall->run('update_issue', function () use ($issue, $session, $title, $category, $problem, $acceptanceCriteria, $plan, $result, $followUps, $notes, $approval, $requester, $assignee): IssueView {
            $actor = $this->currentActor->get();

            return $this->issues->view($this->issueCommands->update(IssueKey::fromString($issue, 'issue'), $title, $problem, $acceptanceCriteria, $plan, $result, $followUps, $notes, $actor, $session, $approval, $category, $requester, $assignee), $actor);
        });
    }
}
