<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\IssueCommands;
use App\Application\Query\IssueQuery;
use App\Application\Query\IssueView;
use App\Domain\Issue\IssueKey;
use App\Domain\Issue\IssueLinkKind;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.26.
 */
#[McpTool(
    name: 'link_issues',
    title: 'Link issues',
    description: 'Link two issues, with the reason in note. Both issues show the link. When issue needs target first, set kind to blocked_by: get_issue then shows blocked_by on issue and blocks on target, and the dependency graph of the project shows the order to the user. Without kind the link is relates: link a follow-up issue to its origin in this way. A second call for the same two issues, in either order, replaces their link, so it changes the kind or the note. A link of an issue to itself fails with invalid_input.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    outputSchema: BundledSchemas::ISSUE,
)]
final readonly class LinkIssuesTool
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
        #[Schema(description: 'Key of the other issue', pattern: Input::ISSUE_KEY)]
        string $target,
        #[Schema(description: 'Markdown: the reason for the link', minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        string $note,
        #[Schema(description: 'relates: no dependency. blocked_by: issue waits for target.')]
        IssueLinkKind $kind = IssueLinkKind::Relates,
    ): array {
        return $this->toolCall->run('link_issues', function () use ($issue, $target, $note, $kind): IssueView {
            $actor = $this->currentActor->get();

            return $this->issues->view($this->issueCommands->link(IssueKey::fromString($issue, 'issue'), IssueKey::fromString($target, 'target'), $kind, $note, $actor), $actor);
        });
    }
}
