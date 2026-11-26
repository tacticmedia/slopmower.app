<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RequestCommandResult;
use App\Application\Query\RequestQuery;
use App\Application\RequestCommands;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\UnlinkCommit;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.10: REST Q8.
 */
#[McpTool(
    name: 'unlink_commit',
    title: 'Unlink commit',
    description: 'Remove a commit link that you made by mistake from a request that you hold.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
)]
final readonly class UnlinkCommitTool
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
        #[Schema(description: 'Full commit SHA', pattern: Input::COMMIT_SHA)]
        string $commitSha,
        #[Schema(description: 'Request ID', pattern: Input::ULID)]
        string $requestId,
    ): array {
        return $this->toolCall->run('unlink_commit', function () use ($commitSha, $requestId): RequestCommandResult {
            $id = RequestId::fromString($requestId, 'requestId');
            $actor = $this->currentActor->get();

            return $this->requests->commandResult($id, $actor, $this->commands->unlinkCommit(new UnlinkCommit($id, CommitSha::fromString($commitSha)), $actor));
        });
    }
}
