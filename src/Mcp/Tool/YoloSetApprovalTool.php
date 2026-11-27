<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\IssueCommands;
use App\Application\Query\IssueQuery;
use App\Application\Query\IssueView;
use App\Domain\Issue\ApprovalMode;
use App\Domain\Issue\IssueKey;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.30.
 */
#[McpTool(
    name: 'yolo_set_approval',
    title: 'Set approval in YOLO mode',
    description: 'Only in YOLO mode: set the approval of an issue to user, agent_review, or self, also lower than before. Read the resource slopmower://yolo_mode first. With YOLO mode off, the call fails with forbidden: ask the user to turn it on, and do not retry. Only in approvalMode by_issue. The rules of the issue tools check the issue with the new approval: with the agent as the requester, change to user with update_issue and requester user. The change writes no entry. The call holds the issue for your session for 2 hours, and is refused on session while another session holds it.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    outputSchema: BundledSchemas::ISSUE,
)]
final readonly class YoloSetApprovalTool
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
        #[Schema(description: 'Who approves the plan and ends this issue: user, agent_review, or self', enum: ['user', 'agent_review', 'self'])]
        ApprovalMode $approval,
    ): array {
        return $this->toolCall->run('yolo_set_approval', function () use ($issue, $session, $approval): IssueView {
            $actor = $this->currentActor->get();

            return $this->issues->view($this->issueCommands->setApprovalInYoloMode(IssueKey::fromString($issue, 'issue'), $approval, $actor, $session), $actor);
        });
    }
}
