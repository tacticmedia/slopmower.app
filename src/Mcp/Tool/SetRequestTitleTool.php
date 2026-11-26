<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RequestCommandResult;
use App\Application\Query\RequestQuery;
use App\Application\RequestCommands;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\SetTitle;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.18: REST Q23. The decider checks the title rules.
 */
#[McpTool(
    name: 'set_request_title',
    title: 'Set request title',
    description: 'Set the title of a request whose title is null: one line of at most 80 characters that summarizes its body. Call it before you claim such a request. Fails with invalid_transition when the request has a title; only the user changes a title.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
)]
final readonly class SetRequestTitleTool
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
        #[Schema(description: 'One line of at most 80 characters that summarizes the body', minLength: 1, maxLength: 200, pattern: Input::NO_NUL)]
        string $title,
    ): array {
        return $this->toolCall->run('set_request_title', function () use ($id, $title): RequestCommandResult {
            $requestId = RequestId::fromString($id, 'id');
            $actor = $this->currentActor->get();

            return $this->requests->commandResult($requestId, $actor, $this->commands->execute(new SetTitle($requestId, $title), $actor));
        });
    }
}
