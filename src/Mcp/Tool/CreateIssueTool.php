<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\IssueCommands;
use App\Application\Query\IssueQuery;
use App\Application\Query\IssueView;
use App\Domain\Issue\ApprovalMode;
use App\Domain\Issue\IssueCategory;
use App\Domain\Issue\IssuePartyKind;
use App\Domain\Issue\IssuePriority;
use App\Domain\Issue\IssueStatus;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.22: REST I6.
 */
#[McpTool(
    name: 'create_issue',
    title: 'Create issue',
    description: 'Create an issue of a project. Its key is the project key, a hyphen, and the next number of the project, for example SM-12. Look through the open issues of the project first, and continue one that covers the request. category is bug when something that exists is wrong against its specification, its documentation, or its evident intent, and change for all else: a feature, an alteration, a refactoring, or an upgrade. status is draft while the problem is incomplete, and backlog or todo when it is ready; backlog and todo need problem. In approvalMode by_issue, choose approval by approvalGuidelines: self or agent_review only where a guideline says so, else user. requester asks for the work and accepts it, and assignee does it: user is the user of your token, agent is any agent. By default the user requests and an agent works. Set requester agent only for work that no user asked for, on an issue whose approval is not user. priority places the issue among the open issues of its category: none, the default, for a follow-up or an idea; a higher value only when the user said how urgent the work is, or the approvalGuidelines say so. Write technical English, as short as possible, and only what a later reader needs.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    outputSchema: BundledSchemas::ISSUE,
)]
final readonly class CreateIssueTool
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
        #[Schema(description: 'Project key from list_projects, for example SM', pattern: Input::PROJECT_KEY)]
        string $project,
        #[Schema(description: 'One line that names the issue', minLength: 1, maxLength: 200, pattern: Input::NO_NUL)]
        string $title,
        #[Schema(description: 'bug: something that exists is wrong. change: all else')]
        IssueCategory $category,
        #[Schema(description: 'Markdown. bug: what is wrong, how to see it, and what is expected. change: what is wanted, and how to tell that it is done', minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        ?string $problem = null,
        #[Schema(description: 'The first status', enum: ['draft', 'backlog', 'todo'])]
        IssueStatus $status = IssueStatus::Draft,
        #[Schema(description: 'Only in approvalMode by_issue: who approves the plan and ends this issue. Null: user', enum: ['user', 'agent_review', 'self', null])]
        ?ApprovalMode $approval = null,
        #[Schema(description: 'user or agent. Null: user')]
        ?IssuePartyKind $requester = null,
        #[Schema(description: 'user or agent. Null: agent')]
        ?IssuePartyKind $assignee = null,
        #[Schema(description: 'The place among the open issues of the category. top: first. urgent, high, normal, low: after the first 10, 25, 50, or 75 percent. none or null: last')]
        ?IssuePriority $priority = null,
    ): array {
        return $this->toolCall->run('create_issue', function () use ($project, $title, $category, $problem, $status, $approval, $requester, $assignee, $priority): IssueView {
            $actor = $this->currentActor->get();

            return $this->issues->view($this->issueCommands->create($project, $title, $category, $problem, $status, $actor, $approval, $requester, $assignee, $priority), $actor);
        });
    }
}
