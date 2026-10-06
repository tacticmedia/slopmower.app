<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\LinkCommitsResult;
use App\Application\RequestCommands;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\LinkCommits;
use App\Mcp\BundledSchemas;
use App\Mcp\McpToolRunner;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.9: REST Q7.
 */
final class LinkCommitsTool
{
    private const string NAME = 'link_commits';

    public function __construct(
        private readonly McpToolRunner $runner,
        private readonly RequestCommands $commands,
        private readonly CurrentActor $currentActor,
    ) {
    }

    /**
     * @param string   $commitSha  Full commit SHA
     * @param string[] $requestIds Every request that this commit covers, all from one review
     * @param ?string  $note       Optional note shown with the link
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: self::NAME,
        title: 'Link commits',
        description: "Record that a commit implements one or more requests that you hold (status in_progress). Use the full SHA from `git rev-parse HEAD`. The commit may be unpushed: the link stays pending until the server's clone has it. Linking does not finish a request.",
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: BundledSchemas::LINK_COMMITS_RESULT,
    )]
    public function linkCommits(
        #[Schema(pattern: McpToolRunner::FULL_SHA)] string $commitSha,
        #[Schema(items: ['type' => 'string', 'pattern' => McpToolRunner::ULID], minItems: 1, maxItems: 50, uniqueItems: true)] array $requestIds,
        #[Schema(maxLength: 65536)] ?string $note = null,
    ): array {
        return $this->runner->run(self::NAME, fn (): LinkCommitsResult => $this->commands->linkCommits(
            new LinkCommits(
                CommitSha::fromString($commitSha),
                array_values(array_map(static fn (string $id): RequestId => RequestId::fromString($id, 'requestIds'), $requestIds)),
                $note,
            ),
            $this->currentActor->get(),
        ));
    }
}
