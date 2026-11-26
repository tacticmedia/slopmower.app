<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Application\Query\LinkPullRequestResult;
use App\Application\RequestCommands;
use App\Domain\Identity\RequestId;
use App\Mcp\BundledSchemas;
use App\Mcp\ToolCall;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * docs/specs/mcp.md section 4.14: REST Q17.
 */
#[McpTool(
    name: 'link_pull_request',
    title: 'Link pull request',
    description: 'Record that a pull request implements one or more requests: each one in_progress and held by you, or ready_for_review. For a GitHub repository the URL is https://github.com/{owner}/{name}/pull/{number} of the reviewed repository. A local repository has no pull requests. Linking does not change the status. Open a pull request when delivery.pullRequest of get_request is true; when it is null, only when the user or the repository\'s instructions ask for it.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    outputSchema: BundledSchemas::LINK_PULL_REQUEST_RESULT,
)]
final readonly class LinkPullRequestTool
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
        #[Schema(description: 'Pull request URL', maxLength: 2048, pattern: Input::PULL_REQUEST_URL)]
        string $url,
        #[Schema(description: 'Every request that this pull request covers, all from one review', items: ['type' => 'string', 'pattern' => Input::ULID], minItems: 1, maxItems: 50, uniqueItems: true)]
        array $requestIds,
        #[Schema(description: 'Optional note shown with the link', maxLength: 65536, pattern: Input::NO_NUL)]
        ?string $note = null,
    ): array {
        return $this->toolCall->run('link_pull_request', fn (): LinkPullRequestResult => $this->commands->linkPullRequest(
            $url,
            array_map(static fn (string $id): RequestId => RequestId::fromString($id, 'requestIds'), $requestIds),
            $note,
            $this->currentActor->get(),
        ));
    }
}
