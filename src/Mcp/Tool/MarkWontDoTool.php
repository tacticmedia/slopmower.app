<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RequestCommandResult;
use App\Application\Query\RequestQuery;
use App\Application\RequestCommands;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\MarkWontDo;
use App\Mcp\BundledSchemas;
use App\Mcp\McpToolRunner;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.12: REST Q11. The decider counts the reason after trimming.
 */
final class MarkWontDoTool
{
    private const string NAME = 'mark_wont_do';

    public function __construct(
        private readonly McpToolRunner $runner,
        private readonly RequestCommands $commands,
        private readonly RequestQuery $requests,
        private readonly CurrentActor $currentActor,
    ) {
    }

    /**
     * @param string $id     Request ID
     * @param string $reason Checkable justification, at least 20 characters
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: self::NAME,
        title: "Mark won't do",
        description: 'Decline an open or reopened request, or one that you hold. Give evidence that the user can check: a file and line, a test result, a conflicting requirement, or why the request is wrong. At least 20 characters. The user then verifies or reopens it.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
    )]
    public function markWontDo(#[Schema(pattern: McpToolRunner::ULID)] string $id, #[Schema(minLength: 20, maxLength: 65536)] string $reason): array
    {
        return $this->runner->run(self::NAME, function () use ($id, $reason): RequestCommandResult {
            $requestId = RequestId::fromString($id, 'id');
            $actor = $this->currentActor->get();

            return $this->requests->commandResult($requestId, $actor, $this->commands->markWontDo(new MarkWontDo($requestId, $reason), $actor));
        });
    }
}
