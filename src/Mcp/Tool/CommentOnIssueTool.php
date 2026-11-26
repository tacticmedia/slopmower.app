<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\IssueCommands;
use App\Application\Query\IssueQuery;
use App\Application\Query\IssueView;
use App\Domain\Identity\IssueEntryId;
use App\Domain\Issue\CommentLabel;
use App\Domain\Issue\IssueKey;
use App\Domain\Request\CommentQuestion;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.25: REST I3.
 */
#[McpTool(
    name: 'comment_on_issue',
    title: 'Comment on issue',
    description: 'Add a Markdown comment to the thread of an issue, in any status. Work on a tracked issue goes into its thread and its notes, not into a new issue. label says what the comment is, and list_comments finds the comments of a project by it. When the user must decide something, put each decision in questions, then hand the issue to the requester with set_issue_status, the status that it has, and waitingFor requester: the user answers each question with Yes, No, or text, and the answers replace your comment, so get_issue has each question with its answer. When the user does not answer, a comment with the label dismissal replaces your comment: do not ask again; decide, or leave the issue as the user left it. Do not ask for the approval of a plan: the user approves it with a button. When a comment of an agent no longer holds, write the new one with replaces, the id of the old one, and do not write a second comment about it: the old one stays in the history, and get_issue and list_comments leave it out. Replace a question comment in this way to withdraw its questions. You cannot replace a comment of a user. A claim of another session never refuses a comment, and a comment renews the claim of your session. Write technical English, as short as possible, and only what a later reader needs.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    outputSchema: BundledSchemas::ISSUE,
)]
final readonly class CommentOnIssueTool
{
    public function __construct(
        private ToolCall $toolCall,
        private IssueCommands $issueCommands,
        private IssueQuery $issues,
        private CurrentActor $currentActor,
    ) {
    }

    /**
     * @param list<array{question: string, detail?: ?string}> $questions
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'Issue key, for example SM-12', pattern: Input::ISSUE_KEY)]
        string $issue,
        #[Schema(description: Input::SESSION, minLength: 1, maxLength: 64, pattern: Input::NO_NUL)]
        string $session,
        #[Schema(description: 'Markdown comment', minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        string $body,
        #[Schema(description: 'comment: a remark. decision: a choice of the user, with its reason. question, answer, and dismissal: the server sets them, and refuses them on a comment without questions. finding: a fact that the user needs, with its source; research goes into notes of update_issue. deviation: the work differs from the plan. scope_creep: work that came up and was left out, or was added. papercut: a small friction in the tools, the code, or the process. review: the findings of a reviewing agent.')]
        CommentLabel $label = CommentLabel::Comment,
        #[Schema(description: 'id of the comment of this issue that the new comment replaces', pattern: Input::ULID)]
        ?string $replaces = null,
        #[Schema(description: 'Questions for the user, each answered with Yes, No, or text: one decision per question, your recommendation in detail. The comment gets the label question.', items: Input::QUESTION, maxItems: 10)]
        array $questions = [],
    ): array {
        $asked = array_map(static fn (array $item): CommentQuestion => new CommentQuestion($item['question'], $item['detail'] ?? null), $questions);

        return $this->toolCall->run('comment_on_issue', function () use ($issue, $session, $body, $label, $replaces, $asked): IssueView {
            $actor = $this->currentActor->get();

            return $this->issues->view($this->issueCommands->comment(IssueKey::fromString($issue, 'issue'), $body, $label, $actor, $session, null === $replaces ? null : IssueEntryId::fromString($replaces, 'replaces'), $asked), $actor);
        });
    }
}
