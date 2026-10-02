<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\MarkReadyForReview;
use App\Application\Query\RequestCommandResult;
use App\Application\Query\RequestQuery;
use App\Domain\Identity\RequestId;
use App\Mcp\BundledSchemas;
use App\Mcp\McpToolRunner;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.11: REST Q10.
 */
final class MarkReadyForReviewTool
{
    private const string NAME = 'mark_ready_for_review';

    public function __construct(
        private readonly McpToolRunner $runner,
        private readonly MarkReadyForReview $markReadyForReview,
        private readonly RequestQuery $requests,
        private readonly CurrentActor $currentActor,
    ) {
    }

    /**
     * @param string $id   Request ID
     * @param string $note What you changed and how you tested it
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: self::NAME,
        title: 'Mark ready for review',
        description: "Tell the user that a request you hold is done. The note says what you changed and how you tested it. Every linked commit must be in the server's clone: for GitHub repositories push first (the server fetches once), otherwise this fails with commits_not_available. A request that needs no code change may have no commits; say why in the note.",
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
    )]
    public function markReadyForReview(#[Schema(pattern: McpToolRunner::ULID)] string $id, #[Schema(minLength: 1, maxLength: 65536)] string $note): array
    {
        return $this->runner->run(self::NAME, function () use ($id, $note): RequestCommandResult {
            $requestId = RequestId::fromString($id, 'id');
            $actor = $this->currentActor->get();

            return $this->requests->commandResult($requestId, $actor, $this->markReadyForReview->markReady($requestId, $note, $actor));
        });
    }
}
