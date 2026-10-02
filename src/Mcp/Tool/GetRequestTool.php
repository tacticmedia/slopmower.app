<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RequestQuery;
use App\Application\Query\RequestView;
use App\Domain\Identity\RequestId;
use App\Mcp\BundledSchemas;
use App\Mcp\McpToolRunner;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.5: REST Q3. No location is computed, so `at` and `context` change nothing and the
 * result has `location` and `excerpt` null.
 */
final class GetRequestTool
{
    private const string NAME = 'get_request';

    public function __construct(
        private readonly McpToolRunner $runner,
        private readonly RequestQuery $requests,
        private readonly CurrentActor $currentActor,
    ) {
    }

    /**
     * @param string  $id      Request ID
     * @param ?string $at      Commit SHA (7-64 hex digits) to locate the anchor at
     * @param ?int    $context Lines of context around the located code to include as excerpt
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: self::NAME,
        title: 'Get request',
        description: "Return one request in full: body, thread, anchor with a code snapshot at the review commit, linked commits, location, and the actions you may take now (allowedActions). `at` locates the anchor at another commit that the server's clone has (pushed, or committed in a local repository); by default the location is at the default-branch head. Your working tree can differ: find the code with anchor.snapshot, its before and after lines, and anchor.symbol.fqn.",
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: BundledSchemas::REQUEST,
    )]
    public function getRequest(#[Schema(pattern: McpToolRunner::ULID)] string $id, #[Schema(pattern: McpToolRunner::SHA_PREFIX)] ?string $at = null, #[Schema(minimum: 0, maximum: 50)] ?int $context = null): array
    {
        return $this->runner->run(self::NAME, fn (): RequestView => $this->requests->get(RequestId::fromString($id, 'id'), $this->currentActor->get()));
    }
}
