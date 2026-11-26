<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\AnchorFactory;
use App\Application\Query\RequestCommandResult;
use App\Application\Query\RequestQuery;
use App\Application\RequestCommands;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Identity\ReviewId;
use App\Domain\Request\Command\Suggest;
use App\Entity\Review;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Repository\ReviewRepository;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.17: REST Q20.
 */
#[McpTool(
    name: 'suggest_request',
    title: 'Suggest request',
    description: 'Suggest one change while you review code. The suggestion is a request that waits for the user, who accepts or dismisses it; do not edit code for it. Give it a title: one line of at most 80 characters that summarizes the body. Use the narrowest anchor: anchorKind line or range with path, startLine, and endLine; symbol with path and the fully qualified name in symbol, for example App\\Billing\\InvoiceTotals::total(); file with path. Without anchorKind the suggestion concerns the whole review. If the server does not find the symbol, use a range. An accepted suggestion comes back through get_updates as a request that waits for you.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
)]
final readonly class SuggestRequestTool
{
    public function __construct(
        private ToolCall $toolCall,
        private ReviewRepository $reviewRepository,
        private AnchorFactory $anchors,
        private RequestCommands $commands,
        private RequestQuery $requests,
        private CurrentActor $currentActor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'Review ID from start_review or list_reviews', pattern: Input::ULID)]
        string $review,
        #[Schema(description: 'One line of at most 80 characters that summarizes the body', minLength: 1, maxLength: 200, pattern: Input::NO_NUL)]
        string $title,
        #[Schema(description: 'Markdown: what to change, and why', minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        string $body,
        #[Schema(description: 'line, range, symbol, or file; null for a general request')]
        ?AnchorKind $anchorKind = null,
        #[Schema(description: 'File path at the review commit; required with anchorKind', minLength: 1, maxLength: 4096, pattern: Input::NO_NUL)]
        ?string $path = null,
        #[Schema(description: 'First line, for line and range', minimum: 1)]
        ?int $startLine = null,
        #[Schema(description: 'Last line, for line and range; equal to startLine for line', minimum: 1)]
        ?int $endLine = null,
        #[Schema(description: 'Fully qualified name of the symbol, for symbol', minLength: 1, maxLength: 1024, pattern: Input::NO_NUL)]
        ?string $symbol = null,
    ): array {
        return $this->toolCall->run('suggest_request', function () use ($review, $title, $body, $anchorKind, $path, $startLine, $endLine, $symbol): RequestCommandResult {
            $found = $this->reviewRepository->findFresh(ReviewId::fromString($review, 'review')) ?? throw new NotFound();
            $anchor = $this->anchor($found, $anchorKind, $path, $startLine, $endLine, $symbol);
            $actor = $this->currentActor->get();
            $events = $this->commands->suggest($found->getId(), new Suggest($anchor, $body, $title), $actor);

            return $this->requests->commandResult($events[0]->requestId ?? throw new \LogicException('request.opened has a requestId.'), $actor, $events);
        });
    }

    /**
     * The anchor capture of Q20 from the flat parameters of suggest_request.
     *
     * @throws InvalidInput Parameters that do not agree with $kind
     */
    private function anchor(Review $review, ?AnchorKind $kind, ?string $path, ?int $startLine, ?int $endLine, ?string $symbol): ?Anchor
    {
        if (null !== $kind) {
            return $this->anchors->capture($review, $kind, $path ?? throw InvalidInput::field('path', 'Is required with anchorKind.'), $startLine, $endLine, $symbol);
        }

        $anchorParameters = array_filter(['path' => $path, 'startLine' => $startLine, 'endLine' => $endLine, 'symbol' => $symbol], static fn (string|int|null $value): bool => null !== $value);

        return [] === $anchorParameters ? null : throw new InvalidInput(array_map(static fn (string $field): array => ['field' => $field, 'message' => 'Must be null without anchorKind.'], array_keys($anchorParameters)));
    }
}
