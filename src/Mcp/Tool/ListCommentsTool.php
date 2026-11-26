<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\IssueCommentPage;
use App\Application\Query\IssueQuery;
use App\Domain\Issue\CommentLabel;
use App\Domain\Issue\IssueKey;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.28: REST I5.
 */
#[McpTool(
    name: 'list_comments',
    title: 'List comments',
    description: 'List the comments of every issue of one project, newest first, each with its issue, its label, its body, and its questions. A replaced comment is left out. issue returns only the comments of that issue. label returns only the comments with that label: every decision, or every papercut, of the project or of one issue is one call. question returns the questions that wait for the user, because the answers and a dismissal replace their question comment.',
    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    outputSchema: BundledSchemas::ISSUE_COMMENT_PAGE,
)]
final readonly class ListCommentsTool
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
        #[Schema(description: 'Only the comments of this issue of the project, for example SM-12', pattern: Input::ISSUE_KEY)]
        ?string $issue = null,
        #[Schema(description: 'Only comments with this label')]
        ?CommentLabel $label = null,
        #[Schema(description: 'nextCursor of the previous page', minLength: 1, pattern: Input::NO_NUL)]
        ?string $cursor = null,
        #[Schema(description: 'Page size', minimum: 1, maximum: 200)]
        int $limit = 50,
    ): array {
        return $this->toolCall->run('list_comments', fn (): IssueCommentPage => $this->issues->comments($project, null === $issue ? null : IssueKey::fromString($issue, 'issue'), $label, $limit, $cursor));
    }
}
