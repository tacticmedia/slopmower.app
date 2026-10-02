<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\UpdatesPage;
use App\Application\Query\UpdatesQuery;
use App\Domain\Error\NotFound;
use App\Domain\Identity\ReviewId;
use App\Mcp\BundledSchemas;
use App\Mcp\McpToolRunner;
use App\Repository\ReviewRepository;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.4: REST V10 with `includeOwn=false`.
 */
final class GetUpdatesTool
{
    private const string NAME = 'get_updates';

    public function __construct(
        private readonly McpToolRunner $runner,
        private readonly ReviewRepository $reviews,
        private readonly UpdatesQuery $updates,
        private readonly CurrentActor $currentActor,
    ) {
    }

    /**
     * @param string $review Review ID from list_reviews
     * @param ?int   $cursor The cursor from the previous call. Null returns everything that waits for you.
     * @param int    $limit  Maximum events (with a cursor) or requests (without one)
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: self::NAME,
        title: 'Get updates',
        description: "Return what changed in one review since `cursor`, grouped by request, leaving out your own actions. Without a cursor, return every request that waits for you with its unseen events. Always keep the returned cursor for the next call; if hasMore is true, call again at once. reviewState 'closed' means stop.",
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: BundledSchemas::UPDATES_PAGE,
    )]
    public function getUpdates(#[Schema(pattern: McpToolRunner::ULID)] string $review, #[Schema(minimum: 0)] ?int $cursor = null, #[Schema(minimum: 1, maximum: 500)] int $limit = 100): array
    {
        return $this->runner->run(self::NAME, function () use ($review, $cursor, $limit): UpdatesPage {
            $reviewId = ReviewId::fromString($review, 'review');
            // An invisible review is not_found before the query checks the cursor.
            $this->reviews->findFresh($reviewId) ?? throw new NotFound();

            return $this->updates->get($reviewId, $this->currentActor->get(), $cursor, $limit, includeOwn: false);
        });
    }
}
