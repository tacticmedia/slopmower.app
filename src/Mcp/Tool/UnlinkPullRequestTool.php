<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RequestCommandResult;
use App\Application\Query\RequestQuery;
use App\Application\RequestCommands;
use App\Domain\Identity\RequestId;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.15: REST Q19.
 */
#[McpTool(
    name: 'unlink_pull_request',
    title: 'Unlink pull request',
    description: 'Remove a pull request link that you made by mistake from a request: in_progress and held by you, or ready_for_review.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
)]
final readonly class UnlinkPullRequestTool
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
        #[Schema(description: 'Pull request URL', maxLength: 2048, pattern: Input::PULL_REQUEST_URL)]
        string $url,
        #[Schema(description: 'Request ID', pattern: Input::ULID)]
        string $requestId,
    ): array {
        return $this->toolCall->run('unlink_pull_request', function () use ($url, $requestId): RequestCommandResult {
            $id = RequestId::fromString($requestId, 'requestId');
            $actor = $this->currentActor->get();

            return $this->requests->commandResult($id, $actor, $this->commands->unlinkPullRequest($id, $url, $actor));
        });
    }
}
