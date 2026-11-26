<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RequestPage;
use App\Application\Query\RequestQuery;
use App\Application\Query\RequestSort;
use App\Controller\Api\Input\RequestListQuery;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Identity\ReviewId;
use App\Domain\Request\Awaiting;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\SuggestionState;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Repository\ReviewRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * docs/specs/mcp.md section 4.3: REST Q1 with `sort=created`, under the rules of its query.
 */
#[McpTool(
    name: 'list_requests',
    title: 'List requests',
    description: 'List the change requests of one review, oldest first, with optional filters. Returns summaries: title is null while a request has none, and summary is the title or else the first line of the body as plain text. Call get_request for the body, thread, anchor, and location. Requests with the same group are one unit of feedback: pass group to read them all, claim each, make one change in one commit, link it to every request of the group, and mark each ready with the same note; with requireApproval, propose the same plan on each.',
    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    outputSchema: BundledSchemas::REQUEST_PAGE,
)]
final readonly class ListRequestsTool
{
    public function __construct(
        private ToolCall $toolCall,
        private ReviewRepository $reviewRepository,
        private RequestQuery $requests,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'Review ID from list_reviews', pattern: Input::ULID)]
        string $review,
        #[Schema(description: 'Only requests with this status')]
        ?RequestStatus $status = null,
        #[Schema(description: 'Only requests where it is this party\'s turn; "agent" means you')]
        ?Awaiting $awaiting = null,
        #[Schema(description: 'Only requests whose file path starts with this prefix; general requests never match', minLength: 1, maxLength: 4096, pattern: Input::NO_NUL)]
        ?string $path = null,
        #[Schema(description: 'Only requests whose code changed (true) or did not change (false) on the default branch')]
        ?bool $outdated = null,
        #[Schema(description: 'Only suggestions in this state; requests that the user opened never match')]
        ?SuggestionState $suggestion = null,
        #[Schema(description: 'Only requests of this group, an exact match; requests without a group never match', minLength: 1, maxLength: 80, pattern: Input::NO_NUL)]
        ?string $group = null,
        #[Schema(description: 'nextCursor of the previous page', minLength: 1, pattern: Input::NO_NUL)]
        ?string $cursor = null,
        #[Schema(description: 'Page size', minimum: 1, maximum: 200)]
        int $limit = 50,
    ): array {
        return $this->toolCall->run('list_requests', function () use ($review, $status, $awaiting, $path, $outdated, $suggestion, $group, $cursor, $limit): RequestPage {
            $found = $this->reviewRepository->findFresh(ReviewId::fromString($review, 'review')) ?? throw new NotFound();
            $query = new RequestListQuery($status?->value, $awaiting?->value, $path, $outdated, $suggestion, RequestSort::Created, $limit, $cursor, $group);
            $violations = array_map(static fn (ConstraintViolationInterface $violation): array => [
                'field' => $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
            ], [...$this->validator->validate($query)]);

            if ([] !== $violations) {
                throw new InvalidInput($violations);
            }

            return $this->requests->list($found, $query->filter(), $limit, $cursor);
        });
    }
}
