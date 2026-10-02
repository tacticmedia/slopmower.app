<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RepositoryPage;
use App\Application\Query\RepositoryQuery;
use App\Mcp\BundledSchemas;
use App\Mcp\McpToolRunner;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.1: REST R1 without the `name` and `source` filters.
 */
final class ListRepositoriesTool
{
    private const string NAME = 'list_repositories';

    public function __construct(
        private readonly McpToolRunner $runner,
        private readonly RepositoryQuery $repositories,
    ) {
    }

    /**
     * @param ?string $cursor nextCursor of the previous page
     * @param int     $limit  Page size
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: self::NAME,
        title: 'List repositories',
        description: 'List the repositories that this token can see: id, source (github or local), name, default branch, and number of open reviews. Use the name or id as `repository` in list_reviews.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: BundledSchemas::REPOSITORY_PAGE,
    )]
    public function listRepositories(#[Schema(minLength: 1)] ?string $cursor = null, #[Schema(minimum: 1, maximum: 500)] int $limit = 100): array
    {
        return $this->runner->run(self::NAME, fn (): RepositoryPage => $this->repositories->list(null, null, $limit, $cursor));
    }
}
