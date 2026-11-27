<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\IssueCommands;
use App\Application\Query\IssueQuery;
use App\Application\Query\IssueView;
use App\Domain\Issue\IssueKey;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.31: REST I11 for an agent.
 */
#[McpTool(
    name: 'yolo_release_claim',
    title: 'Release claim in YOLO mode',
    description: 'Only in YOLO mode: remove the claim of any session on an issue, as the Release button of the user does. Read the resource slopmower://yolo_mode first. With YOLO mode off, the call fails with forbidden: ask the user to turn it on, and do not retry. The call takes no claim and writes no entry. The next write of a session takes the issue.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    outputSchema: BundledSchemas::ISSUE,
)]
final readonly class YoloReleaseClaimTool
{
    public function __construct(
        private ToolCall $toolCall,
        private IssueCommands $issueCommands,
        private IssueQuery $issues,
        private CurrentActor $currentActor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'Issue key, for example SM-12', pattern: Input::ISSUE_KEY)]
        string $issue,
    ): array {
        return $this->toolCall->run('yolo_release_claim', function () use ($issue): IssueView {
            $actor = $this->currentActor->get();

            return $this->issues->view($this->issueCommands->release(IssueKey::fromString($issue, 'issue'), $actor), $actor);
        });
    }
}
