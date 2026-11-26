<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RepositoryPage;
use App\Application\Query\RepositoryQuery;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.1: REST R1 without the `name` and `source` filters.
 */
#[McpTool(
    name: 'list_repositories',
    title: 'List repositories',
    description: 'List the repositories that this token can see: id, source (github or local), name, default branch, and number of open reviews. Use the name or id as `repository` in list_reviews.',
    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    outputSchema: BundledSchemas::REPOSITORY_PAGE,
)]
final readonly class ListRepositoriesTool
{
    public function __construct(
        private ToolCall $toolCall,
        private RepositoryQuery $repositories,
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
        return $this->toolCall->run('list_repositories', fn (): RepositoryPage => $this->repositories->list(null, null, $limit, $cursor));
    }
}
