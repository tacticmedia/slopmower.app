<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Application\AnchorFactory;
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
use App\Application\Query\ReviewView;
use App\Application\Query\UpdatesPage;
use App\Application\Query\UpdatesQuery;
use App\Application\RateLimits;
use App\Application\RequestCommands;
use App\Application\ReviewCommands;
use App\Controller\Api\Input\RequestListQuery;
use App\Domain\Actor\Actor;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Error\DomainError;
use App\Domain\Error\ErrorCode;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Event\RecordedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Git\CommitShaPrefix;
use App\Domain\Git\RefName;
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
use App\Domain\Request\Command\SetTitle;
use App\Domain\Request\Command\Suggest;
use App\Domain\Request\Command\UnlinkCommit;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\SuggestionState;
use App\Entity\Review;
use App\Repository\ReviewRepository;
use App\Security\CurrentActor;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use Sentry\State\HubInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The handlers of the tools of docs/specs/mcp-tools.json, which {@see ToolLoader} registers, and the adapter of
 * docs/specs/mcp.md section 3: the normalizers of REST, and every failure as an isError result, because the SDK turns any
 * other exception into a bare -32603.
 */
final class McpTools
{
    public function __construct(
        private readonly RequestCommands $commands,
        private readonly ReviewCommands $reviewCommands,
        private readonly AnchorFactory $anchors,
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
        // The Sentry bundle runs in prod only.
        private readonly ?HubInterface $sentryHub = null,
    ) {
    }

    /**
     * Section 4.6: REST Q5.
     *
     * @return array<string, mixed>
     */
    public function claimRequest(string $id): array
    {
        return $this->requestCommand('claim_request', $id, 'id', fn (RequestId $requestId, Actor $actor): array => $this->commands->execute(new Claim($requestId), $actor));
    }

    /**
     * Section 4.5: REST Q3.
     *
     * @return array<string, mixed>
     */
    public function getRequest(string $id, ?string $at = null, ?int $context = null): array
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
     * @return array<string, mixed>
     */
    public function getUpdates(string $review, ?int $cursor = null, int $limit = 100): array
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
     * @param list<string> $requestIds
     *
     * @return array<string, mixed>
     */
    public function linkCommits(
        string $commitSha,
        array $requestIds,
        ?string $note = null,
    ): array {
        return $this->run('link_commits', fn (): LinkCommitsResult => $this->commands->linkCommits(
            new LinkCommits(
                CommitSha::fromString($commitSha),
                array_map(static fn (string $id): RequestId => RequestId::fromString($id, 'requestIds'), $requestIds),
                $note,
            ),
            $this->currentActor->get(),
        ));
    }

    /**
     * Section 4.14: REST Q17.
     *
     * @param list<string> $requestIds
     *
     * @return array<string, mixed>
     */
    public function linkPullRequest(
        string $url,
        array $requestIds,
        ?string $note = null,
    ): array {
        return $this->run('link_pull_request', fn (): LinkPullRequestResult => $this->commands->linkPullRequest(
            $url,
            array_map(static fn (string $id): RequestId => RequestId::fromString($id, 'requestIds'), $requestIds),
            $note,
            $this->currentActor->get(),
        ));
    }

    /**
     * Section 4.1: REST R1 without the `name` and `source` filters.
     *
     * @return array<string, mixed>
     */
    public function listRepositories(?string $cursor = null, int $limit = 100): array
    {
        return $this->run('list_repositories', fn (): RepositoryPage => $this->repositories->list(null, null, $limit, $cursor));
    }

    /**
     * Section 4.3: REST Q1 with `sort=created`, under the rules of its query.
     *
     * @return array<string, mixed>
     */
    public function listRequests(
        string $review,
        ?RequestStatus $status = null,
        ?Awaiting $awaiting = null,
        ?string $path = null,
        ?bool $outdated = null,
        ?SuggestionState $suggestion = null,
        ?string $group = null,
        ?string $cursor = null,
        int $limit = 50,
    ): array {
        return $this->run('list_requests', function () use ($review, $status, $awaiting, $path, $outdated, $suggestion, $group, $cursor, $limit): RequestPage {
            $found = $this->reviewRepository->findFresh(ReviewId::fromString($review, 'review')) ?? throw new NotFound();
            $query = new RequestListQuery($status?->value, $awaiting?->value, $path, $outdated, $suggestion, RequestSort::Created, $limit, $cursor, $group);
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
     * @return array<string, mixed>
     */
    public function listReviews(string $repository, bool $includeClosed = false, ?string $cursor = null, int $limit = 50): array
    {
        return $this->run('list_reviews', function () use ($repository, $includeClosed, $cursor, $limit): ReviewPage {
            $match = $this->repositories->list($repository, null, 1, null)->items[0] ?? throw new NotFound();

            return $this->reviews->list($match->repository->getId(), $includeClosed ? ReviewStateFilter::All : ReviewStateFilter::Open, $limit, $cursor);
        });
    }

    /**
     * Section 4.11: REST Q10.
     *
     * @return array<string, mixed>
     */
    public function markReadyForReview(string $id, string $note): array
    {
        return $this->requestCommand('mark_ready_for_review', $id, 'id', fn (RequestId $requestId, Actor $actor): array => $this->commands->markReadyForReview(new MarkReadyForReview($requestId, $note), $actor));
    }

    /**
     * Section 4.12: REST Q11. The decider counts the reason after trimming.
     *
     * @return array<string, mixed>
     */
    public function markWontDo(string $id, string $reason): array
    {
        return $this->requestCommand('mark_wont_do', $id, 'id', fn (RequestId $requestId, Actor $actor): array => $this->commands->execute(new MarkWontDo($requestId, $reason), $actor));
    }

    /**
     * Section 4.13: REST Q14. The decider checks the diff format.
     *
     * @return array<string, mixed>
     */
    public function proposeSolution(string $id, string $plan, ?string $diff = null): array
    {
        return $this->requestCommand('propose_solution', $id, 'id', fn (RequestId $requestId, Actor $actor): array => $this->commands->execute(new ProposeSolution($requestId, $plan, $diff), $actor));
    }

    /**
     * Section 4.7: REST Q6.
     *
     * @return array<string, mixed>
     */
    public function releaseRequest(string $id, ?string $note = null): array
    {
        return $this->requestCommand('release_request', $id, 'id', fn (RequestId $requestId, Actor $actor): array => $this->commands->execute(new Release($requestId, $note), $actor));
    }

    /**
     * Section 4.8: REST Q4. The decider refuses a body of whitespace only.
     *
     * @return array<string, mixed>
     */
    public function replyToRequest(string $id, string $body, bool $question = false): array
    {
        return $this->requestCommand('reply_to_request', $id, 'id', fn (RequestId $requestId, Actor $actor): array => $this->commands->execute(new Reply($requestId, $body, $question), $actor));
    }

    /**
     * Section 4.18: REST Q23. The decider checks the title rules.
     *
     * @return array<string, mixed>
     */
    public function setRequestTitle(string $id, string $title): array
    {
        return $this->requestCommand('set_request_title', $id, 'id', fn (RequestId $requestId, Actor $actor): array => $this->commands->execute(new SetTitle($requestId, $title), $actor));
    }

    /**
     * Section 4.16: REST R1 with `name`, then V1.
     *
     * @return array<string, mixed>
     */
    public function startReview(string $repository, ?string $ref = null, ?string $title = null): array
    {
        return $this->run('start_review', function () use ($repository, $ref, $title): ReviewView {
            $match = $this->repositories->list($repository, null, 1, null)->items[0] ?? throw new NotFound();
            $reviewId = $this->reviewCommands->start($match->repository->getId(), null === $ref ? null : RefName::fromString($ref), $title, $this->currentActor->get());

            return $this->reviews->get($reviewId);
        });
    }

    /**
     * Section 4.17: REST Q20.
     *
     * @return array<string, mixed>
     */
    public function suggestRequest(
        string $review,
        string $title,
        string $body,
        ?AnchorKind $anchorKind = null,
        ?string $path = null,
        ?int $startLine = null,
        ?int $endLine = null,
        ?string $symbol = null,
    ): array {
        return $this->run('suggest_request', function () use ($review, $title, $body, $anchorKind, $path, $startLine, $endLine, $symbol): RequestCommandResult {
            $found = $this->reviewRepository->findFresh(ReviewId::fromString($review, 'review')) ?? throw new NotFound();
            $anchor = $this->anchor($found, $anchorKind, $path, $startLine, $endLine, $symbol);
            $actor = $this->currentActor->get();
            $events = $this->commands->suggest($found->getId(), new Suggest($anchor, $body, $title), $actor);

            return $this->requests->commandResult($events[0]->requestId ?? throw new \LogicException('request.opened has a requestId.'), $actor, $events);
        });
    }

    /**
     * Section 4.10: REST Q8.
     *
     * @return array<string, mixed>
     */
    public function unlinkCommit(string $commitSha, string $requestId): array
    {
        return $this->requestCommand('unlink_commit', $requestId, 'requestId', fn (RequestId $id, Actor $actor): array => $this->commands->unlinkCommit(new UnlinkCommit($id, CommitSha::fromString($commitSha)), $actor));
    }

    /**
     * Section 4.15: REST Q19.
     *
     * @return array<string, mixed>
     */
    public function unlinkPullRequest(string $url, string $requestId): array
    {
        return $this->requestCommand('unlink_pull_request', $requestId, 'requestId', fn (RequestId $id, Actor $actor): array => $this->commands->unlinkPullRequest($id, $url, $actor));
    }

    /**
     * The anchor capture of Q20 from the flat parameters of suggest_request.
     *
     * @throws InvalidInput Parameters that do not agree with $kind
     */
    private function anchor(Review $review, ?AnchorKind $kind, ?string $path, ?int $startLine, ?int $endLine, ?string $symbol): ?Anchor
    {
        if (null !== $kind) {
            return $this->anchors->capture($review, $kind, $path ?? throw InvalidInput::field('path', 'Is required with anchorKind.'), $startLine, $endLine, $symbol);
        }

        $anchorParameters = array_filter(['path' => $path, 'startLine' => $startLine, 'endLine' => $endLine, 'symbol' => $symbol], static fn (string|int|null $value): bool => null !== $value);

        return [] === $anchorParameters ? null : throw new InvalidInput(array_map(static fn (string $field): array => ['field' => $field, 'message' => 'Must be null without anchorKind.'], array_keys($anchorParameters)));
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
            $this->sentryHub?->captureException($throwable);

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
