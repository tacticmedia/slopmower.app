<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RequestCommandResult;
use App\Application\Query\RequestQuery;
use App\Application\UnlinkCommit;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Mcp\BundledSchemas;
use App\Mcp\McpToolRunner;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.10: REST Q8.
 */
final class UnlinkCommitTool
{
    private const string NAME = 'unlink_commit';

    public function __construct(
        private readonly McpToolRunner $runner,
        private readonly UnlinkCommit $unlinkCommit,
        private readonly RequestQuery $requests,
        private readonly CurrentActor $currentActor,
    ) {
    }

    /**
     * @param string $commitSha Full commit SHA
     * @param string $requestId Request ID
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: self::NAME,
        title: 'Unlink commit',
        description: 'Remove a commit link that you made by mistake from a request that you hold.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
    )]
    public function unlinkCommit(#[Schema(pattern: McpToolRunner::FULL_SHA)] string $commitSha, #[Schema(pattern: McpToolRunner::ULID)] string $requestId): array
    {
        return $this->runner->run(self::NAME, function () use ($commitSha, $requestId): RequestCommandResult {
            $id = RequestId::fromString($requestId, 'requestId');
            $actor = $this->currentActor->get();

            return $this->requests->commandResult($id, $actor, $this->unlinkCommit->unlink($id, CommitSha::fromString($commitSha), $actor));
        });
    }
}
