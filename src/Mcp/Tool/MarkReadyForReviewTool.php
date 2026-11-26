<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RequestCommandResult;
use App\Application\Query\RequestQuery;
use App\Application\RequestCommands;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.11: REST Q10.
 */
#[McpTool(
    name: 'mark_ready_for_review',
    title: 'Mark ready for review',
    description: 'Tell the user that a request you hold is done. The note says what you changed and how you tested it. Every linked commit must be in the server\'s clone: for GitHub repositories push first (the server fetches once), otherwise this fails with commits_not_available. A request that needs no code change may have no commits; say why in the note.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
)]
final readonly class MarkReadyForReviewTool
{
    public function __construct(
        private ToolCall $toolCall,
        private RequestCommands $commands,
        private RequestQuery $requests,
        private CurrentActor $currentActor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'Request ID', pattern: Input::ULID)]
        string $id,
        #[Schema(description: 'What you changed and how you tested it', minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        string $note,
    ): array {
        return $this->toolCall->run('mark_ready_for_review', function () use ($id, $note): RequestCommandResult {
            $requestId = RequestId::fromString($id, 'id');
            $actor = $this->currentActor->get();

            return $this->requests->commandResult($requestId, $actor, $this->commands->markReadyForReview(new MarkReadyForReview($requestId, $note), $actor));
        });
    }
}
