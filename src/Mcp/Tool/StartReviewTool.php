<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RepositoryQuery;
use App\Application\Query\ReviewQuery;
use App\Application\Query\ReviewView;
use App\Application\ReviewCommands;
use App\Domain\Error\NotFound;
use App\Domain\Git\RefName;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.16: REST R1 with `name`, then V1.
 */
#[McpTool(
    name: 'start_review',
    title: 'Start review',
    description: 'Start a review of one repository, pinned to one commit, when the user asks you to review code and no review was named. Without ref, the review pins the head of the default branch. Read the code at the returned commitSha, then call suggest_request once per finding.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    outputSchema: BundledSchemas::REVIEW,
)]
final readonly class StartReviewTool
{
    public function __construct(
        private ToolCall $toolCall,
        private RepositoryQuery $repositories,
        private ReviewCommands $reviewCommands,
        private ReviewQuery $reviews,
        private CurrentActor $currentActor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'Repository ID, GitHub owner/name, or local directory name', minLength: 1, maxLength: 255, pattern: Input::NO_NUL)]
        string $repository,
        #[Schema(description: 'Branch, tag, or commit SHA to pin; null for the default branch', minLength: 1, maxLength: 255, pattern: Input::NO_NUL)]
        ?string $ref = null,
        #[Schema(description: 'Review title; null for "<ref> @ <short SHA>"', minLength: 1, maxLength: 200, pattern: Input::NO_NUL)]
        ?string $title = null,
    ): array {
        return $this->toolCall->run('start_review', function () use ($repository, $ref, $title): ReviewView {
            $match = $this->repositories->list($repository, null, 1, null)->items[0] ?? throw new NotFound();
            $reviewId = $this->reviewCommands->start($match->repository->getId(), null === $ref ? null : RefName::fromString($ref), $title, $this->currentActor->get());

            return $this->reviews->get($reviewId);
        });
    }
}
