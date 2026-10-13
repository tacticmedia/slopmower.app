<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Application\Query\LinkCommitsResult;
use App\Application\Query\LinkPullRequestResult;
use App\Application\Query\RepositoryPage;
use App\Application\Query\RepositoryQuery;
use App\Application\Query\RequestCommandResult;
use App\Application\Query\RequestPage;
use App\Application\Query\RequestQuery;
use App\Application\Query\RequestSort;
use App\Application\Query\RequestView;
use App\Application\Query\ReviewPage;
use App\Application\Query\ReviewQuery;
use App\Application\Query\ReviewStateFilter;
use App\Application\Query\UpdatesPage;
use App\Application\Query\UpdatesQuery;
use App\Application\RateLimits;
use App\Application\RequestCommands;
use App\Controller\Api\Input\RequestListQuery;
use App\Domain\Actor\Actor;
use App\Domain\Error\DomainError;
use App\Domain\Error\ErrorCode;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Event\RecordedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Git\CommitShaPrefix;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Request\Awaiting;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Domain\Request\Command\MarkWontDo;
use App\Domain\Request\Command\ProposeSolution;
use App\Domain\Request\Command\Release;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\Command\UnlinkCommit;
use App\Domain\Request\RequestStatus;
use App\Repository\ReviewRepository;
use App\Security\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The tools of docs/specs/mcp.md section 4 and the adapter of its section 3: the normalizers of REST, and every failure
 * as an isError result, because the SDK turns any other exception into a bare -32603. The methods are sorted by tool
 * name, and their order is the order of tools/list.
 */
final class McpTools
{
    /** The `#[Schema]` patterns of docs/specs/mcp.md section 3. */
    private const string ULID = '^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$';
    private const string FULL_SHA = '^([0-9a-f]{40}|[0-9a-f]{64})$';
    private const string SHA_PREFIX = '^[0-9a-f]{7,64}$';
    private const string PULL_REQUEST_URL = '^https://\S+$';

    public function __construct(
        private readonly RequestCommands $commands,
        private readonly RequestQuery $requests,
        private readonly RepositoryQuery $repositories,
        private readonly ReviewQuery $reviews,
        private readonly UpdatesQuery $updates,
        private readonly ReviewRepository $reviewRepository,
        private readonly CurrentActor $currentActor,
        private readonly RateLimits $rateLimits,
        private readonly NormalizerInterface $normalizer,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Section 4.6: REST Q5.
     *
     * @param string $id Request ID
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'claim_request',
        title: 'Claim request',
        description: 'Claim an open or reopened request before you edit code for it. Status becomes in_progress. Claiming a request that you already hold succeeds and changes nothing. Fails with invalid_transition when the status does not allow it or another agent holds it: then take the next request.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
    )]
    public function claimRequest(#[Schema(pattern: self::ULID)] string $id): array
    {
        return $this->requestCommand('claim_request', $id, 'id', fn (RequestId $requestId, Actor $actor): array => $this->commands->claim(new Claim($requestId), $actor));
    }

    /**
     * Section 4.5: REST Q3.
     *
     * @param string  $id      Request ID
     * @param ?string $at      Commit SHA (7-64 hex digits) to locate the anchor at
     * @param ?int    $context Lines of context around the located code to include as excerpt
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_request',
        title: 'Get request',
        description: "Return one request in full: body, thread, anchor with a code snapshot at the review commit, linked commits, location, and the actions you may take now (allowedActions). `at` locates the anchor at another commit that the server's clone has (pushed, or committed in a local repository); by default the location is at the default-branch head. Your working tree can differ: find the code with anchor.snapshot, its before and after lines, and anchor.symbol.fqn. A general request has anchor, location, and excerpt null. It belongs to the whole review, and its body and thread say what to change.",
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: BundledSchemas::REQUEST,
    )]
    public function getRequest(#[Schema(pattern: self::ULID)] string $id, #[Schema(pattern: self::SHA_PREFIX)] ?string $at = null, #[Schema(minimum: 0, maximum: 50)] ?int $context = null): array
    {
        return $this->run('get_request', fn (): RequestView => $this->requests->get(
            RequestId::fromString($id, 'id'),
            $this->currentActor->get(),
            null === $at ? null : CommitShaPrefix::fromString($at, 'at'),
            $context,
        ));
    }

    /**
     * Section 4.4: REST V10 with `includeOwn=false`.
     *
     * @param string $review Review ID from list_reviews
     * @param ?int   $cursor The cursor from the previous call. Null returns everything that waits for you.
     * @param int    $limit  Maximum events (with a cursor) or requests (without one)
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_updates',
        title: 'Get updates',
        description: "Return what changed in one review since `cursor`, grouped by request, leaving out your own actions. Without a cursor, return every request that waits for you with its unseen events. Always keep the returned cursor for the next call; if hasMore is true, call again at once. reviewState 'closed' means stop.",
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: BundledSchemas::UPDATES_PAGE,
    )]
    public function getUpdates(#[Schema(pattern: self::ULID)] string $review, #[Schema(minimum: 0)] ?int $cursor = null, #[Schema(minimum: 1, maximum: 500)] int $limit = 100): array
    {
        return $this->run('get_updates', function () use ($review, $cursor, $limit): UpdatesPage {
            $reviewId = ReviewId::fromString($review, 'review');
            // An invisible review is not_found before the query checks the cursor.
            $this->reviewRepository->findFresh($reviewId) ?? throw new NotFound();

            return $this->updates->get($reviewId, $this->currentActor->get(), $cursor, $limit, includeOwn: false);
        });
    }

    /**
     * Section 4.9: REST Q7.
     *
     * @param string   $commitSha  Full commit SHA
     * @param string[] $requestIds Every request that this commit covers, all from one review
     * @param ?string  $note       Optional note shown with the link
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'link_commits',
        title: 'Link commits',
        description: "Record that a commit implements one or more requests that you hold (status in_progress). Use the full SHA from `git rev-parse HEAD`. The commit may be unpushed: the link stays pending until the server's clone has it. Linking does not finish a request.",
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: BundledSchemas::LINK_COMMITS_RESULT,
    )]
    public function linkCommits(
        #[Schema(pattern: self::FULL_SHA)] string $commitSha,
        #[Schema(items: ['type' => 'string', 'pattern' => self::ULID], minItems: 1, maxItems: 50, uniqueItems: true)] array $requestIds,
        #[Schema(maxLength: 65536)] ?string $note = null,
    ): array {
        return $this->run('link_commits', fn (): LinkCommitsResult => $this->commands->linkCommits(
            new LinkCommits(
                CommitSha::fromString($commitSha),
                array_values(array_map(static fn (string $id): RequestId => RequestId::fromString($id, 'requestIds'), $requestIds)),
                $note,
            ),
            $this->currentActor->get(),
        ));
    }

    /**
     * Section 4.14: REST Q17.
     *
     * @param string   $url        Pull request URL
     * @param string[] $requestIds Every request that this pull request covers, all from one review
     * @param ?string  $note       Optional note shown with the link
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'link_pull_request',
        title: 'Link pull request',
        description: "Record that a pull request implements one or more requests: each one in_progress and held by you, or ready_for_review. For a GitHub repository the URL is https://github.com/{owner}/{name}/pull/{number} of the reviewed repository. Linking does not change the status. Open a pull request only when the user or the repository's instructions ask for it.",
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: BundledSchemas::LINK_PULL_REQUEST_RESULT,
    )]
    public function linkPullRequest(
        #[Schema(pattern: self::PULL_REQUEST_URL, maxLength: 2048)] string $url,
        #[Schema(items: ['type' => 'string', 'pattern' => self::ULID], minItems: 1, maxItems: 50, uniqueItems: true)] array $requestIds,
        #[Schema(maxLength: 65536)] ?string $note = null,
    ): array {
        return $this->run('link_pull_request', fn (): LinkPullRequestResult => $this->commands->linkPullRequest(
            $url,
            array_values(array_map(static fn (string $id): RequestId => RequestId::fromString($id, 'requestIds'), $requestIds)),
            $note,
            $this->currentActor->get(),
        ));
    }

    /**
     * Section 4.1: REST R1 without the `name` and `source` filters.
     *
     * @param ?string $cursor nextCursor of the previous page
     * @param int     $limit  Page size
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_repositories',
        title: 'List repositories',
        description: 'List the repositories that this token can see: id, source (github or local), name, default branch, and number of open reviews. Use the name or id as `repository` in list_reviews.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: BundledSchemas::REPOSITORY_PAGE,
    )]
    public function listRepositories(#[Schema(minLength: 1)] ?string $cursor = null, #[Schema(minimum: 1, maximum: 500)] int $limit = 100): array
    {
        return $this->run('list_repositories', fn (): RepositoryPage => $this->repositories->list(null, null, $limit, $cursor));
    }

    /**
     * Section 4.3: REST Q1 with `sort=created`, under the rules of its query.
     *
     * @param string         $review   Review ID from list_reviews
     * @param ?RequestStatus $status   Only requests with this status
     * @param ?Awaiting      $awaiting Only requests where it is this party's turn; "agent" means you
     * @param ?string        $path     Only requests whose file path starts with this prefix; general requests never match
     * @param ?bool          $outdated Only requests whose code changed (true) or did not change (false) on the default branch
     * @param ?string        $cursor   nextCursor of the previous page
     * @param int            $limit    Page size
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_requests',
        title: 'List requests',
        description: 'List the change requests of one review, oldest first, with optional filters. Returns summaries; call get_request for the body, thread, anchor, and location.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: BundledSchemas::REQUEST_PAGE,
    )]
    public function listRequests(
        #[Schema(pattern: self::ULID)] string $review,
        ?RequestStatus $status = null,
        ?Awaiting $awaiting = null,
        #[Schema(minLength: 1, maxLength: 4096)] ?string $path = null,
        ?bool $outdated = null,
        #[Schema(minLength: 1)] ?string $cursor = null,
        #[Schema(minimum: 1, maximum: 200)] int $limit = 50,
    ): array {
        return $this->run('list_requests', function () use ($review, $status, $awaiting, $path, $outdated, $cursor, $limit): RequestPage {
            $found = $this->reviewRepository->findFresh(ReviewId::fromString($review, 'review')) ?? throw new NotFound();
            $query = new RequestListQuery($status?->value, $awaiting?->value, $path, $outdated, RequestSort::Created, $limit, $cursor);
            $violations = array_map(static fn (ConstraintViolationInterface $violation): array => [
                'field' => $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
            ], [...$this->validator->validate($query)]);

            if ([] !== $violations) {
                throw new InvalidInput($violations);
            }

            return $this->requests->list($found, $query->filter(), $limit, $cursor);
        });
    }

    /**
     * Section 4.2: REST R1 with `name`, then V2.
     *
     * @param string  $repository    Repository ID, GitHub owner/name, or local directory name
     * @param bool    $includeClosed Also list closed reviews
     * @param ?string $cursor        nextCursor of the previous page
     * @param int     $limit         Page size
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_reviews',
        title: 'List reviews',
        description: 'List the reviews of one repository, newest first. Only open reviews unless includeClosed is true. Each review is pinned to one commit and holds its own requests; pass its id as `review` to get_updates and list_requests.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: BundledSchemas::REVIEW_PAGE,
    )]
    public function listReviews(#[Schema(minLength: 1, maxLength: 255)] string $repository, bool $includeClosed = false, #[Schema(minLength: 1)] ?string $cursor = null, #[Schema(minimum: 1, maximum: 100)] int $limit = 50): array
    {
        return $this->run('list_reviews', function () use ($repository, $includeClosed, $cursor, $limit): ReviewPage {
            $match = $this->repositories->list($repository, null, 1, null)->items[0] ?? throw new NotFound();

            return $this->reviews->list($match->repository->getId(), $includeClosed ? ReviewStateFilter::All : ReviewStateFilter::Open, $limit, $cursor);
        });
    }

    /**
     * Section 4.11: REST Q10.
     *
     * @param string $id   Request ID
     * @param string $note What you changed and how you tested it
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'mark_ready_for_review',
        title: 'Mark ready for review',
        description: "Tell the user that a request you hold is done. The note says what you changed and how you tested it. Every linked commit must be in the server's clone: for GitHub repositories push first (the server fetches once), otherwise this fails with commits_not_available. A request that needs no code change may have no commits; say why in the note.",
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
    )]
    public function markReadyForReview(#[Schema(pattern: self::ULID)] string $id, #[Schema(minLength: 1, maxLength: 65536)] string $note): array
    {
        return $this->requestCommand('mark_ready_for_review', $id, 'id', fn (RequestId $requestId, Actor $actor): array => $this->commands->markReadyForReview(new MarkReadyForReview($requestId, $note), $actor));
    }

    /**
     * Section 4.12: REST Q11. The decider counts the reason after trimming.
     *
     * @param string $id     Request ID
     * @param string $reason Checkable justification, at least 20 characters
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'mark_wont_do',
        title: "Mark won't do",
        description: 'Decline an open or reopened request, or one that you hold. Give evidence that the user can check: a file and line, a test result, a conflicting requirement, or why the request is wrong. At least 20 characters. The user then verifies or reopens it.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
    )]
    public function markWontDo(#[Schema(pattern: self::ULID)] string $id, #[Schema(minLength: 20, maxLength: 65536)] string $reason): array
    {
        return $this->requestCommand('mark_wont_do', $id, 'id', fn (RequestId $requestId, Actor $actor): array => $this->commands->markWontDo(new MarkWontDo($requestId, $reason), $actor));
    }

    /**
     * Section 4.13: REST Q14. The decider checks the diff format.
     *
     * @param string  $id   Request ID
     * @param string  $plan Markdown plan: what you will change, and why
     * @param ?string $diff Optional unified diff of the change from `git diff`, at most 9 files
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'propose_solution',
        title: 'Propose solution',
        description: 'Propose how you will solve a request that you hold, before you link commits. Required when the request has requireApproval true; also useful when the request is unclear or large. A new proposal replaces the current one. The user approves it or requests changes: the decision comes back through get_updates, and get_request shows it in proposal.state. Until the user approves, link_commits and mark_ready_for_review fail with invalid_transition.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
    )]
    public function proposeSolution(#[Schema(pattern: self::ULID)] string $id, #[Schema(minLength: 1, maxLength: 65536)] string $plan, #[Schema(minLength: 1, maxLength: 65536)] ?string $diff = null): array
    {
        return $this->requestCommand('propose_solution', $id, 'id', fn (RequestId $requestId, Actor $actor): array => $this->commands->propose(new ProposeSolution($requestId, $plan, $diff), $actor));
    }

    /**
     * Section 4.7: REST Q6.
     *
     * @param string  $id   Request ID
     * @param ?string $note Why you stop, for the user
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'release_request',
        title: 'Release request',
        description: 'Give back a request that you claimed but will not finish. Its status returns to what it was before the claim (open or reopened).',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
    )]
    public function releaseRequest(#[Schema(pattern: self::ULID)] string $id, #[Schema(maxLength: 65536)] ?string $note = null): array
    {
        return $this->requestCommand('release_request', $id, 'id', fn (RequestId $requestId, Actor $actor): array => $this->commands->release(new Release($requestId, $note), $actor));
    }

    /**
     * Section 4.8: REST Q4. The decider refuses a body of whitespace only.
     *
     * @param string $id       Request ID
     * @param string $body     Markdown comment
     * @param bool   $question True when you need an answer from the user
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'reply_to_request',
        title: 'Reply to request',
        description: "Add a comment to a request's thread, in any status. Set question to true when you need the user's answer; the request then waits for the user and comes back through get_updates when they reply.",
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
    )]
    public function replyToRequest(#[Schema(pattern: self::ULID)] string $id, #[Schema(minLength: 1, maxLength: 65536)] string $body, bool $question = false): array
    {
        return $this->requestCommand('reply_to_request', $id, 'id', fn (RequestId $requestId, Actor $actor): array => $this->commands->reply(new Reply($requestId, $body, $question), $actor));
    }

    /**
     * Section 4.10: REST Q8.
     *
     * @param string $commitSha Full commit SHA
     * @param string $requestId Request ID
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'unlink_commit',
        title: 'Unlink commit',
        description: 'Remove a commit link that you made by mistake from a request that you hold.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
    )]
    public function unlinkCommit(#[Schema(pattern: self::FULL_SHA)] string $commitSha, #[Schema(pattern: self::ULID)] string $requestId): array
    {
        return $this->requestCommand('unlink_commit', $requestId, 'requestId', fn (RequestId $id, Actor $actor): array => $this->commands->unlinkCommit(new UnlinkCommit($id, CommitSha::fromString($commitSha)), $actor));
    }

    /**
     * Section 4.15: REST Q19.
     *
     * @param string $url       Pull request URL
     * @param string $requestId Request ID
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'unlink_pull_request',
        title: 'Unlink pull request',
        description: 'Remove a pull request link that you made by mistake from a request: in_progress and held by you, or ready_for_review.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: BundledSchemas::REQUEST_COMMAND_RESULT,
    )]
    public function unlinkPullRequest(#[Schema(pattern: self::PULL_REQUEST_URL, maxLength: 2048)] string $url, #[Schema(pattern: self::ULID)] string $requestId): array
    {
        return $this->requestCommand('unlink_pull_request', $requestId, 'requestId', fn (RequestId $id, Actor $actor): array => $this->commands->unlinkPullRequest($id, $url, $actor));
    }

    /**
     * @param \Closure(RequestId, Actor): list<RecordedEvent> $command
     *
     * @return array<string, mixed>
     */
    private function requestCommand(string $tool, string $id, string $idField, \Closure $command): array
    {
        return $this->run($tool, function () use ($id, $idField, $command): RequestCommandResult {
            $requestId = RequestId::fromString($id, $idField);
            $actor = $this->currentActor->get();

            return $this->requests->commandResult($requestId, $actor, $command($requestId, $actor));
        });
    }

    /**
     * Consumes the rate limits of the call first (docs/specs/mcp.md section 3).
     *
     * @param \Closure(): object $call
     *
     * @return array<string, mixed> The REST JSON of the result
     *
     * @throws ToolCallException With the Error JSON as its message
     */
    private function run(string $tool, \Closure $call): array
    {
        try {
            $this->rateLimits->consumeCall();
            $normalized = $this->normalizer->normalize($call(), 'json');
        } catch (DomainError $error) {
            throw new ToolCallException($this->errorJson($error->code(), $error->getMessage(), $error->details()), previous: $error);
        } catch (\Throwable $throwable) {
            $this->logger->error('The MCP tool {tool} failed.', ['tool' => $tool, 'exception' => $throwable]);

            throw new ToolCallException($this->errorJson(ErrorCode::InternalError, 'An unexpected error occurred.', null));
        }

        \assert(\is_array($normalized));

        return $normalized;
    }

    /**
     * @param array<string, mixed>|null $details
     */
    private function errorJson(ErrorCode $code, string $message, ?array $details): string
    {
        return json_encode(
            ['error' => ['code' => $code->value, 'message' => $message, 'details' => $this->normalizer->normalize($details, 'json')]],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }
}
