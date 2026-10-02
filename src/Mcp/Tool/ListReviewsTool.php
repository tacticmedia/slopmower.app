<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RepositoryQuery;
use App\Application\Query\ReviewPage;
use App\Application\Query\ReviewQuery;
use App\Application\Query\ReviewStateFilter;
use App\Domain\Error\NotFound;
use App\Mcp\BundledSchemas;
use App\Mcp\McpToolRunner;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.2: REST R1 with `name`, then V2.
 */
final class ListReviewsTool
{
    private const string NAME = 'list_reviews';

    public function __construct(
        private readonly McpToolRunner $runner,
        private readonly RepositoryQuery $repositories,
        private readonly ReviewQuery $reviews,
    ) {
    }

    /**
     * @param string  $repository    Repository ID, GitHub owner/name, or local directory name
     * @param bool    $includeClosed Also list closed reviews
     * @param ?string $cursor        nextCursor of the previous page
     * @param int     $limit         Page size
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: self::NAME,
        title: 'List reviews',
        description: 'List the reviews of one repository, newest first. Only open reviews unless includeClosed is true. Each review is pinned to one commit and holds its own requests; pass its id as `review` to get_updates and list_requests.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: BundledSchemas::REVIEW_PAGE,
    )]
    public function listReviews(#[Schema(minLength: 1, maxLength: 255)] string $repository, bool $includeClosed = false, #[Schema(minLength: 1)] ?string $cursor = null, #[Schema(minimum: 1, maximum: 100)] int $limit = 50): array
    {
        return $this->runner->run(self::NAME, function () use ($repository, $includeClosed, $cursor, $limit): ReviewPage {
            $match = $this->repositories->list($repository, null, 1, null)->items[0] ?? throw new NotFound();

            return $this->reviews->list($match->repository->getId(), $includeClosed ? ReviewStateFilter::All : ReviewStateFilter::Open, $limit, $cursor);
        });
    }
}
