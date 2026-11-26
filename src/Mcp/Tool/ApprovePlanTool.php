<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\IssueCommands;
use App\Application\Query\IssueQuery;
use App\Application\Query\IssueView;
use App\Domain\Identity\IssueEntryId;
use App\Domain\Issue\IssueKey;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.27.
 */
#[McpTool(
    name: 'approve_plan',
    title: 'Approve plan',
    description: 'Record that another agent or subagent reviewed the plan of an issue. Use it in a project with approvalMode agent_review, before you set in_progress. The reviewer reads plan of planRevision and notes, and checks that plan solves problem and that notes does nothing that plan does not say; reviewer is its name and reviewNote its findings. The author of a plan does not review it. Fails with invalid_input in approvalMode user, for an issue without a plan, and for a planRevision that is not the newest: then call get_issue and review the newest plan. A plan with new text clears the approval. The call holds the issue for your session for 2 hours, and is refused on session while another session holds it.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    outputSchema: BundledSchemas::ISSUE,
)]
final readonly class ApprovePlanTool
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
        #[Schema(description: 'planRevision of the issue, as the reviewer read it', pattern: Input::ULID)]
        string $planRevision,
        #[Schema(description: 'Name of the agent or subagent that reviewed the plan', minLength: 1, maxLength: 64, pattern: Input::NO_NUL)]
        string $reviewer,
        #[Schema(description: 'Markdown: the findings of the reviewer, at least 20 characters', minLength: 20, maxLength: 65536, pattern: Input::NO_NUL)]
        string $reviewNote,
    ): array {
        return $this->toolCall->run('approve_plan', function () use ($issue, $session, $planRevision, $reviewer, $reviewNote): IssueView {
            $actor = $this->currentActor->get();

            return $this->issues->view($this->issueCommands->approvePlan(IssueKey::fromString($issue, 'issue'), IssueEntryId::fromString($planRevision, 'planRevision'), $reviewer, $reviewNote, $actor, $session), $actor);
        });
    }
}
