<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RequestQuery;
use App\Application\Query\RequestView;
use App\Domain\Git\CommitShaPrefix;
use App\Domain\Identity\RequestId;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.5: REST Q3.
 */
#[McpTool(
    name: 'get_request',
    title: 'Get request',
    description: 'Return one request in full: title, body, thread, anchor with a code snapshot at the review commit, linked commits, location, how to deliver the work (delivery), and the actions you may take now (allowedActions). `at` locates the anchor at another commit that the server\'s clone has (pushed, or committed in a local repository); by default the location is at the default-branch head. Your working tree can differ: find the code with anchor.snapshot, its before and after lines, and anchor.symbol.fqn. A general request has anchor, location, and excerpt null. It belongs to the whole review, and its body and thread say what to change. A request with title null has no title: give it one with set_request_title before you claim it. type says what the user asks for: change, a code change; question, claim it, answer with reply_to_request, change no code, then mark_ready_for_review with a note that points to the answer; rule, claim it, add the rule as one bullet in the user\'s words under `## Rules from Slopmower` in AGENTS.md at the repository root (create the file when it is missing), add `@AGENTS.md` to CLAUDE.md when it exists and does not import AGENTS.md, fix the anchored code when it breaks the rule, all in one commit, then link, mark ready, and follow the rule from then on. group, when not null, makes the request one unit of feedback with the other requests of that group: read them with list_requests and group, and handle them together in one commit.',
    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    outputSchema: BundledSchemas::REQUEST,
)]
final readonly class GetRequestTool
{
    public function __construct(
        private ToolCall $toolCall,
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
        #[Schema(description: 'Commit SHA (7-64 hex digits) to locate the anchor at', pattern: Input::COMMIT_SHA_PREFIX)]
        ?string $at = null,
        #[Schema(description: 'Lines of context around the located code to include as excerpt', minimum: 0, maximum: 50)]
        ?int $context = null,
    ): array {
        return $this->toolCall->run('get_request', fn (): RequestView => $this->requests->get(
            RequestId::fromString($id, 'id'),
            $this->currentActor->get(),
            null === $at ? null : CommitShaPrefix::fromString($at, 'at'),
            $context,
        ));
    }
}
