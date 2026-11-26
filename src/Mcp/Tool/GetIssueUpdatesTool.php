<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\IssueQuery;
use App\Application\Query\IssueUpdatesPage;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.29: REST I10.
 */
#[McpTool(
    name: 'get_issue_updates',
    title: 'Get issue updates',
    description: 'Return what changed in one project since cursor: the new entries of the threads of its issues, oldest first, each with the summary of its issue, as get_issue reads them without history. Your own entries and replaced entries are left out; another session with your token name counts as you. Without a cursor, return the issues in todo, in_progress, in_review, and approved, and the newest cursor. Always keep the returned cursor for the next call; if hasMore is true, call again at once. Poll it while an issue waits for the user: answers are a comment with the label answer, a dismissal is a comment with the label dismissal, an approval is a plan_approved entry. A title change, a link, and a new issue without a problem write no entry.',
    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    outputSchema: BundledSchemas::ISSUE_UPDATES_PAGE,
)]
final readonly class GetIssueUpdatesTool
{
    public function __construct(
        private ToolCall $toolCall,
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
        #[Schema(description: 'The cursor from the previous call. Null returns the issues in work and the newest cursor.', minimum: 0)]
        ?int $cursor = null,
        #[Schema(description: 'Maximum entries (with a cursor) or issues (without one)', minimum: 1, maximum: 200)]
        int $limit = 50,
    ): array {
        return $this->toolCall->run('get_issue_updates', fn (): IssueUpdatesPage => $this->issues->updates($project, $this->currentActor->get(), $cursor, $limit));
    }
}
