<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RequestCommandResult;
use App\Application\Query\RequestQuery;
use App\Application\RequestCommands;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\Release;
use App\Mcp\BundledSchemas;
use App\Mcp\McpToolRunner;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.7: REST Q6.
 */
final class ReleaseRequestTool
{
    private const string NAME = 'release_request';

    public function __construct(
        private readonly McpToolRunner $runner,
        private readonly RequestCommands $commands,
        private readonly RequestQuery $requests,
        private readonly CurrentActor $currentActor,
    ) {
    }

    /**
     * @param string  $id   Request ID
     * @param ?string $note Why you stop, for the user
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: self::NAME,
        title: 'Release request',
        description: 'Give back a request that you claimed but will not finish. Its status returns to what it was before the claim (open or reopened).',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
    )]
    public function releaseRequest(#[Schema(pattern: McpToolRunner::ULID)] string $id, #[Schema(maxLength: 65536)] ?string $note = null): array
    {
        return $this->runner->run(self::NAME, function () use ($id, $note): RequestCommandResult {
            $requestId = RequestId::fromString($id, 'id');
            $actor = $this->currentActor->get();

            return $this->requests->commandResult($requestId, $actor, $this->commands->release(new Release($requestId, $note), $actor));
        });
    }
}
