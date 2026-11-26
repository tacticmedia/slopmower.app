<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RepositoryQuery;
use App\Application\Query\ReviewPage;
use App\Application\Query\ReviewQuery;
use App\Application\Query\ReviewStateFilter;
use App\Domain\Error\NotFound;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.2: REST R1 with `name`, then V2.
 */
#[McpTool(
    name: 'list_reviews',
    title: 'List reviews',
    description: 'List the reviews of one repository, newest first. Only open reviews unless includeClosed is true. Each review is pinned to one commit and holds its own requests; pass its id as `review` to get_updates and list_requests.',
    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    outputSchema: BundledSchemas::REVIEW_PAGE,
)]
final readonly class ListReviewsTool
{
    public function __construct(
        private ToolCall $toolCall,
        private RepositoryQuery $repositories,
        private ReviewQuery $reviews,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'Repository ID, GitHub owner/name, or local directory name', minLength: 1, maxLength: 255, pattern: Input::NO_NUL)]
        string $repository,
        #[Schema(description: 'Also list closed reviews')]
        bool $includeClosed = false,
        #[Schema(description: 'nextCursor of the previous page', minLength: 1, pattern: Input::NO_NUL)]
        ?string $cursor = null,
        #[Schema(description: 'Page size', minimum: 1, maximum: 100)]
        int $limit = 50,
    ): array {
        return $this->toolCall->run('list_reviews', function () use ($repository, $includeClosed, $cursor, $limit): ReviewPage {
            $match = $this->repositories->list($repository, null, 1, null)->items[0] ?? throw new NotFound();

            return $this->reviews->list($match->repository->getId(), $includeClosed ? ReviewStateFilter::All : ReviewStateFilter::Open, $limit, $cursor);
        });
    }
}
