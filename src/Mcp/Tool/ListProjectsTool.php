<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\IssueQuery;
use App\Application\Query\ProjectPage;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.19: REST P1.
 */
#[McpTool(
    name: 'list_projects',
    title: 'List projects',
    description: 'List the projects of the issue tracker that this token sees, with key, name, approvalMode, approvalGuidelines, and the names of their repositories. Track the work on a repository as issues of the project that lists it. A token of one repository sees only the project of that repository.',
    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    outputSchema: BundledSchemas::PROJECT_PAGE,
)]
final readonly class ListProjectsTool
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
        #[Schema(description: 'nextCursor of the previous page', minLength: 1, pattern: Input::NO_NUL)]
        ?string $cursor = null,
        #[Schema(description: 'Page size', minimum: 1, maximum: 500)]
        int $limit = 100,
    ): array {
        return $this->toolCall->run('list_projects', fn (): ProjectPage => $this->issues->projects($limit, $cursor));
    }
}
