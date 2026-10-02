<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RequestCommandResult;
use App\Application\Query\RequestQuery;
use App\Application\RequestCommands;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\Reply;
use App\Mcp\BundledSchemas;
use App\Mcp\McpToolRunner;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.8: REST Q4. The decider refuses a body of whitespace only.
 */
final class ReplyToRequestTool
{
    private const string NAME = 'reply_to_request';

    public function __construct(
        private readonly McpToolRunner $runner,
        private readonly RequestCommands $commands,
        private readonly RequestQuery $requests,
        private readonly CurrentActor $currentActor,
    ) {
    }

    /**
     * @param string $id       Request ID
     * @param string $body     Markdown comment
     * @param bool   $question True when you need an answer from the user
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: self::NAME,
        title: 'Reply to request',
        description: "Add a comment to a request's thread, in any status. Set question to true when you need the user's answer; the request then waits for the user and comes back through get_updates when they reply.",
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
    )]
    public function replyToRequest(#[Schema(pattern: McpToolRunner::ULID)] string $id, #[Schema(minLength: 1, maxLength: 65536)] string $body, bool $question = false): array
    {
        return $this->runner->run(self::NAME, function () use ($id, $body, $question): RequestCommandResult {
            $requestId = RequestId::fromString($id, 'id');
            $actor = $this->currentActor->get();

            return $this->requests->commandResult($requestId, $actor, $this->commands->reply(new Reply($requestId, $body, $question), $actor));
        });
    }
}
