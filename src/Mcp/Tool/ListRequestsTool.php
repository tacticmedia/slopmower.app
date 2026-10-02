<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RequestPage;
use App\Application\Query\RequestQuery;
use App\Application\Query\RequestSort;
use App\Controller\Api\Input\RequestListQuery;
use App\Domain\Error\NotFound;
use App\Domain\Identity\ReviewId;
use App\Domain\Request\Awaiting;
use App\Domain\Request\RequestStatus;
use App\Mcp\BundledSchemas;
use App\Mcp\McpToolRunner;
use App\Repository\ReviewRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.3: REST Q1 with `sort=created`, under the rules of its query.
 */
final class ListRequestsTool
{
    private const string NAME = 'list_requests';

    public function __construct(
        private readonly McpToolRunner $runner,
        private readonly ReviewRepository $reviews,
        private readonly RequestQuery $requests,
    ) {
    }

    /**
     * @param string         $review   Review ID from list_reviews
     * @param ?RequestStatus $status   Only requests with this status
     * @param ?Awaiting      $awaiting Only requests where it is this party's turn; "agent" means you
     * @param ?string        $path     Only requests whose file path starts with this prefix
     * @param ?bool          $outdated Only requests whose code changed (true) or did not change (false) on the default branch
     * @param ?string        $cursor   nextCursor of the previous page
     * @param int            $limit    Page size
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: self::NAME,
        title: 'List requests',
        description: 'List the change requests of one review, oldest first, with optional filters. Returns summaries; call get_request for the body, thread, anchor, and location.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: BundledSchemas::REQUEST_PAGE,
    )]
    public function listRequests(
        #[Schema(pattern: McpToolRunner::ULID)] string $review,
        ?RequestStatus $status = null,
        ?Awaiting $awaiting = null,
        #[Schema(minLength: 1, maxLength: 4096)] ?string $path = null,
        ?bool $outdated = null,
        #[Schema(minLength: 1)] ?string $cursor = null,
        #[Schema(minimum: 1, maximum: 200)] int $limit = 50,
    ): array {
        return $this->runner->run(self::NAME, function () use ($review, $status, $awaiting, $path, $outdated, $cursor, $limit): RequestPage {
            $found = $this->reviews->findFresh(ReviewId::fromString($review, 'review')) ?? throw new NotFound();
            $query = new RequestListQuery($status?->value, $awaiting?->value, $path, $outdated, RequestSort::Created, $limit, $cursor);
            $this->runner->validate($query);

            return $this->requests->list($found, $query->filter(), $limit, $cursor);
        });
    }
}
