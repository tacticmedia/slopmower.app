<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RequestCommandResult;
use App\Application\Query\RequestQuery;
use App\Application\RequestCommands;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\MarkWontDo;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.12: REST Q11. The decider counts the reason after trimming.
 */
#[McpTool(
    name: 'mark_wont_do',
    title: 'Mark won\'t do',
    description: 'Decline an open or reopened request, or one that you hold. Give evidence that the user can check: a file and line, a test result, a conflicting requirement, or why the request is wrong. At least 20 characters. The user then verifies or reopens it.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
)]
final readonly class MarkWontDoTool
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
        #[Schema(description: 'Checkable justification, at least 20 characters', minLength: 20, maxLength: 65536, pattern: Input::NO_NUL)]
        string $reason,
    ): array {
        return $this->toolCall->run('mark_wont_do', function () use ($id, $reason): RequestCommandResult {
            $requestId = RequestId::fromString($id, 'id');
            $actor = $this->currentActor->get();

            return $this->requests->commandResult($requestId, $actor, $this->commands->execute(new MarkWontDo($requestId, $reason), $actor));
        });
    }
}
