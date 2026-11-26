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
    description: 'Change the title, the category, or the sections of an issue. Null leaves a field as it is; give at least one field. A section is Markdown. problem: for a bug, what is wrong, how to see it, and what is expected; for a change, what is wanted, and how to tell that it is done. plan: the steps, the files, and the tests. result: what was done, with the commit SHAs, and each deviation from the plan. followUps: what came up and was left out. A plan with new text clears its approval. An agent cannot change plan in in_progress, in_review, or approved, and no field of an issue in done or wont_do. approval exists only in approvalMode by_issue, and an agent changes it only to user, and with the agent as the requester also sends requester user. requester and assignee change without an entry, and the issue then waits for the new party of its waitingFor. The write holds the issue for your session for 2 hours. While another session holds it, the write is refused on session: take another issue. Write technical English, as short as possible, and only what a later reader needs.',
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
        #[Schema(description: 'Markdown. bug: what is wrong, how to see it, and what is expected. change: what is wanted, and how to tell that it is done', minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        ?string $problem = null,
        #[Schema(description: 'Markdown: the steps, the files, and the tests', minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        ?string $plan = null,
        #[Schema(description: 'Markdown: what was done, with the commit SHAs, and each deviation from the plan', minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        ?string $result = null,
        #[Schema(description: 'Markdown: what came up and was left out', minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        ?string $followUps = null,
        #[Schema(description: 'Only in approvalMode by_issue: who approves the plan and ends this issue. An agent changes it only to user', enum: ['user', 'agent_review', 'self', null])]
        ?ApprovalMode $approval = null,
        #[Schema(description: 'user or agent. Null keeps it')]
        ?IssuePartyKind $requester = null,
        #[Schema(description: 'user or agent. Null keeps it')]
        ?IssuePartyKind $assignee = null,
    ): array {
        return $this->toolCall->run('update_issue', function () use ($issue, $session, $title, $category, $problem, $plan, $result, $followUps, $approval, $requester, $assignee): IssueView {
            $actor = $this->currentActor->get();

            return $this->issues->view($this->issueCommands->update(IssueKey::fromString($issue, 'issue'), $title, $problem, $plan, $result, $followUps, $actor, $session, $approval, $category, $requester, $assignee), $actor);
        });
    }
}
