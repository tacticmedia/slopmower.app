<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\IssuePage;
use App\Application\Query\IssueQuery;
use App\Application\Query\IssueSort;
use App\Domain\Issue\IssueCategory;
use App\Domain\Issue\IssuePartyKind;
use App\Domain\Issue\IssueStatus;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.20: REST I1.
 */
#[McpTool(
    name: 'list_issues',
    title: 'List issues',
    description: 'List the issues of one project as summaries. By default in the order of the user: the bugs first, then by rank, and without status only the open issues. Work from the top: the next issue is status todo, waitingForKind agent, limit 1. claim names the agent session that holds an issue: skip an issue of another session. waitingFor names the party with the next step: work on an issue that waits for an agent, and leave an issue that waits for a user. Call get_issue for the sections, the links, and the thread.',
    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    outputSchema: BundledSchemas::ISSUE_PAGE,
)]
final readonly class ListIssuesTool
{
    public function __construct(
        private ToolCall $toolCall,
        private IssueQuery $issues,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'Project key from list_projects, for example SM', pattern: Input::PROJECT_KEY)]
        string $project,
        #[Schema(description: 'rank: the order of the user, bugs first. updated: newest updatedAt first')]
        IssueSort $sort = IssueSort::Rank,
        #[Schema(description: 'Only issues with this status. Null: all with sort updated, the open ones with sort rank')]
        ?IssueStatus $status = null,
        #[Schema(description: 'Only issues of this category')]
        ?IssueCategory $category = null,
        #[Schema(description: 'Only issues that wait for a party of this kind: agent for the issues that you can work on')]
        ?IssuePartyKind $waitingForKind = null,
        #[Schema(description: 'Only issues with activity in the last N hours. At most one activity filter', minimum: 1, maximum: 8760)]
        ?int $activeWithinHours = null,
        #[Schema(description: 'Only issues without activity for N hours, an issue without activity counted from its creation', minimum: 1, maximum: 8760)]
        ?int $idleForHours = null,
        #[Schema(description: 'true: only issues without activity: no comment, status change, section version, or plan approval since the creation')]
        bool $noActivity = false,
        #[Schema(description: 'nextCursor of the previous page', minLength: 1, pattern: Input::NO_NUL)]
        ?string $cursor = null,
        #[Schema(description: 'Page size', minimum: 1, maximum: 200)]
        int $limit = 50,
    ): array {
        return $this->toolCall->run('list_issues', fn (): IssuePage => $this->issues->list($project, $status, $category, $limit, $cursor, $waitingForKind, $sort, $activeWithinHours, $idleForHours, $noActivity));
    }
}
