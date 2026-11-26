<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\LinkCommitsResult;
use App\Application\RequestCommands;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\LinkCommits;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.9: REST Q7.
 */
#[McpTool(
    name: 'link_commits',
    title: 'Link commits',
    description: 'Record that a commit implements one or more requests that you hold (status in_progress). Use the full SHA from `git rev-parse HEAD`. The commit may be unpushed: the link stays pending until the server\'s clone has it. Linking does not finish a request.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    outputSchema: BundledSchemas::LINK_COMMITS_RESULT,
)]
final readonly class LinkCommitsTool
{
    public function __construct(
        private ToolCall $toolCall,
        private RequestCommands $commands,
        private CurrentActor $currentActor,
    ) {
    }

    /**
     * @param list<string> $requestIds
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'Full commit SHA', pattern: Input::COMMIT_SHA)]
        string $commitSha,
        #[Schema(description: 'Every request that this commit covers, all from one review', items: ['type' => 'string', 'pattern' => Input::ULID], minItems: 1, maxItems: 50, uniqueItems: true)]
        array $requestIds,
        #[Schema(description: 'Optional note shown with the link', maxLength: 65536, pattern: Input::NO_NUL)]
        ?string $note = null,
    ): array {
        return $this->toolCall->run('link_commits', fn (): LinkCommitsResult => $this->commands->linkCommits(
            new LinkCommits(
                CommitSha::fromString($commitSha),
                array_map(static fn (string $id): RequestId => RequestId::fromString($id, 'requestIds'), $requestIds),
                $note,
            ),
            $this->currentActor->get(),
        ));
    }
}
