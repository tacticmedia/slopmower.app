<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\IssueDetail;
use App\Application\Query\IssueQuery;
use App\Domain\Issue\IssueKey;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.21: REST I2.
 */
#[McpTool(
    name: 'get_issue',
    title: 'Get issue',
    description: 'Read one issue: the five sections problem, plan, result, followUps, and notes, planRevision, the plan approval, allowedStatuses, its links, and its thread. By default the thread is the current content: it has no replaced entry, and a section_changed entry names its section and holds no text, because the issue has it. history: true returns the full log: every entry, also each earlier version of a section with its text in body, and each replaced comment. replacedBy of an entry is the id of the entry that replaced it, or null.',
    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    outputSchema: BundledSchemas::ISSUE_DETAIL,
)]
final readonly class GetIssueTool
{
    public function __construct(
        private ToolCall $toolCall,
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
        #[Schema(description: 'true: also the replaced entries, and the text of each section version')]
        bool $history = false,
    ): array {
        return $this->toolCall->run('get_issue', fn (): IssueDetail => $this->issues->get(IssueKey::fromString($issue, 'issue'), $this->currentActor->get(), $history));
    }
}
