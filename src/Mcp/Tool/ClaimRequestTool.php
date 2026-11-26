<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RequestCommandResult;
use App\Application\Query\RequestQuery;
use App\Application\RequestCommands;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\Claim;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.6: REST Q5.
 */
#[McpTool(
    name: 'claim_request',
    title: 'Claim request',
    description: 'Claim an open or reopened request before you edit code for it. Status becomes in_progress. Claiming a request that you already hold succeeds and changes nothing. Fails with invalid_transition when the status does not allow it or another agent holds it: then take the next request.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
)]
final readonly class ClaimRequestTool
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
    ): array {
        return $this->toolCall->run('claim_request', function () use ($id): RequestCommandResult {
            $requestId = RequestId::fromString($id, 'id');
            $actor = $this->currentActor->get();

            return $this->requests->commandResult($requestId, $actor, $this->commands->execute(new Claim($requestId), $actor));
        });
    }
}
