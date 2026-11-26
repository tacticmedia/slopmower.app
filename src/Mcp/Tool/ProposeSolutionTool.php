<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RequestCommandResult;
use App\Application\Query\RequestQuery;
use App\Application\RequestCommands;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\ProposeSolution;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.13: REST Q14. The decider checks the diff format.
 */
#[McpTool(
    name: 'propose_solution',
    title: 'Propose solution',
    description: 'Propose how you will solve a request that you hold, before you link commits. Required when the request has requireApproval true; also useful when the request is unclear or large. A new proposal replaces the current one. The user approves it or requests changes: the decision comes back through get_updates, and get_request shows it in proposal.state. Until the user approves, link_commits and mark_ready_for_review fail with invalid_transition.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
)]
final readonly class ProposeSolutionTool
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
        #[Schema(description: 'Markdown plan: what you will change, and why', minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        string $plan,
        #[Schema(description: 'Optional unified diff of the change from `git diff`, at most 9 files', minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        ?string $diff = null,
    ): array {
        return $this->toolCall->run('propose_solution', function () use ($id, $plan, $diff): RequestCommandResult {
            $requestId = RequestId::fromString($id, 'id');
            $actor = $this->currentActor->get();

            return $this->requests->commandResult($requestId, $actor, $this->commands->execute(new ProposeSolution($requestId, $plan, $diff), $actor));
        });
    }
}
