<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\RequestCommandResult;
use App\Application\Query\RequestQuery;
use App\Application\RequestCommands;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\CommentQuestion;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.8: REST Q4. The decider refuses a body of whitespace only.
 */
#[McpTool(
    name: 'reply_to_request',
    title: 'Reply to request',
    description: 'Add a comment to a request\'s thread, in any status. Set question to true when you need the user\'s answer; the request then waits for the user and comes back through get_updates when they reply. Put each question you need answered in questions: the user answers each with Yes, No, or text, and the answers come back as a user comment whose questions hold each question with its answer.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
)]
final readonly class ReplyToRequestTool
{
    public function __construct(
        private ToolCall $toolCall,
        private RequestCommands $commands,
        private RequestQuery $requests,
        private CurrentActor $currentActor,
    ) {
    }

    /**
     * @param list<array{question: string, detail?: ?string}> $questions
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'Request ID', pattern: Input::ULID)]
        string $id,
        #[Schema(description: 'Markdown comment', minLength: 1, maxLength: 65536, pattern: Input::NO_NUL)]
        string $body,
        #[Schema(description: 'True when you need an answer from the user')]
        bool $question = false,
        #[Schema(description: 'Questions for the user, each answered with Yes, No, or text. A comment with questions is a question.', items: Input::QUESTION, maxItems: 10)]
        array $questions = [],
    ): array {
        $asked = array_map(static fn (array $item): CommentQuestion => new CommentQuestion($item['question'], $item['detail'] ?? null), $questions);

        return $this->toolCall->run('reply_to_request', function () use ($id, $body, $question, $asked): RequestCommandResult {
            $requestId = RequestId::fromString($id, 'id');
            $actor = $this->currentActor->get();

            return $this->requests->commandResult($requestId, $actor, $this->commands->execute(new Reply($requestId, $body, $question, questions: $asked), $actor));
        });
    }
}
