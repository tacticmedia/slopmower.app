<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Anchoring\Locator;
use App\Application\AnchorFactory;
use App\Application\FetchRepository;
use App\Application\Query\CarryOverItem;
use App\Application\Query\CarryOverOutcome;
use App\Application\Query\CarryOverResult;
use App\Application\Query\RequestQuery;
use App\Application\Query\RequestSummaryView;
use App\Application\UsageLimits;
use App\Controller\Api\Input\CarryOverInput;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Anchor\LineRange;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\LimitExceeded;
use App\Domain\Error\NotFound;
use App\Domain\Event\DecidedEvent;
use App\Domain\Event\RequestCommentedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Location\LocationStatus;
use App\Domain\Location\OrphanReason;
use App\Domain\Request\CarriedFrom;
use App\Domain\Request\Command\Open;
use App\Domain\Request\RequestDecider;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\SuggestionState;
use App\Entity\ChangeRequest;
use App\Entity\Review;
use App\Entity\User;
use App\EventStore\CommandExecutor;
use App\Repository\ChangeRequestRepository;
use App\Repository\ReviewEventRepository;
use App\Repository\ReviewRepository;
use App\Security\CurrentActor;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * V15 of docs/specs/rest-api.md section 5. The git work runs before the event transaction, which checks the copies
 * and the statuses again under the tenant lock.
 *
 * @phpstan-type Plan array{anchor: Anchor|null, comment: string|null, location: LocationStatus|null}
 */
final class CarryOverController extends AbstractController
{
    private const int SHORT_SHA_CHARACTERS = 12;

    /** Keeps a snapshot comment below the 65,536 characters of a comment body. */
    private const int MAX_SNAPSHOT_CHARACTERS = 60000;

    public function __construct(
        private readonly ReviewRepository $reviews,
        private readonly ChangeRequestRepository $requests,
        private readonly ReviewEventRepository $events,
        private readonly RequestQuery $requestQuery,
        private readonly FetchRepository $fetchRepository,
        private readonly Locator $locator,
        private readonly AnchorFactory $anchors,
        private readonly CommandExecutor $executor,
        private readonly UsageLimits $usageLimits,
        private readonly CurrentActor $currentActor,
    ) {
    }

    #[Route('/api/v1/reviews/{reviewId}/carry-over', name: 'api_review_carry_over', methods: ['POST'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function carryOver(
        #[MapEntity(id: 'reviewId')] Review $review,
        #[MapRequestPayload(acceptFormat: 'json')] CarryOverInput $input,
    ): JsonResponse {
        \assert(null !== $input->fromReviewId);
        $source = $this->reviews->find(ReviewId::fromString($input->fromReviewId)) ?? throw new NotFound();
        self::assertCarriable($review, $source);
        $plans = $this->plans($review, $source);
        $carried = $this->carry($review, $plans);

        return $this->json($this->result($review, $source, $plans, $carried));
    }

    /**
     * @throws InvalidInput
     */
    private static function assertCarriable(Review $target, Review $source): void
    {
        if ($source->getId()->equals($target->getId())) {
            throw InvalidInput::field('fromReviewId', 'Must name another review than the target review.');
        }

        if (!$source->getRepository()->getId()->equals($target->getRepository()->getId())) {
            throw InvalidInput::field('fromReviewId', 'Must name a review of the same repository.');
        }

        if (null !== $target->getClosedAt()) {
            throw InvalidInput::field('', 'The review is closed.');
        }
    }

    /**
     * Locates and captures each source request that can carry and has no copy in the target review yet.
     *
     * @return array<string, Plan|OrphanReason> By source request ID. An orphan reason when the anchor file is gone.
     */
    private function plans(Review $target, Review $source): array
    {
        $copies = $this->copies($target->getId());
        $carriable = array_filter(
            $this->sources($source->getId()),
            static fn (ChangeRequest $request): bool => !isset($copies[$request->getId()->toString()]) && self::canCarry($request),
        );

        if ([] !== array_filter($carriable, static fn (ChangeRequest $request): bool => null !== $request->getAnchor())) {
            // The caller of Locator::locate() checks the clone.
            $this->fetchRepository->cloneReady($target->getRepository()->getId());
        }

        $openedEventIds = $this->events->findOpenedEventIds($source->getId());
        $plans = [];

        foreach ($carriable as $request) {
            $id = $request->getId()->toString();
            $anchor = $request->getAnchor();
            $plans[$id] = null === $anchor
                ? ['anchor' => null, 'comment' => null, 'location' => null]
                : $this->reanchor($target, $anchor, $openedEventIds[$id] ?? throw new \LogicException("The request {$id} has no request.opened event."));
        }

        return $plans;
    }

    /**
     * The table of V15: the location at the target review commit gives the new anchor and the first comment.
     *
     * @return Plan|OrphanReason
     */
    private function reanchor(Review $target, Anchor $anchor, EventId $openedEventId): array|OrphanReason
    {
        $repository = $target->getRepository();
        $targetSha = $target->getCommitSha();
        $location = $this->locator->locate($repository, $openedEventId, $anchor, $targetSha);

        if (null !== $location->path && LocationStatus::Orphaned !== $location->status) {
            $moved = $this->capture($target, $anchor, $location->path, $location->range);

            if (null !== $moved) {
                $comment = LocationStatus::Changed === $location->status ? self::changedComment($anchor->commitSha, $targetSha) : null;

                return ['anchor' => $moved, 'comment' => $comment, 'location' => $location->status];
            }
        }

        $path = $location->path ?? $this->locator->mappedPath($repository, $anchor, $targetSha);

        if (null === $path) {
            return $location->reason ?? throw new \LogicException('A location without a path is orphaned.');
        }

        return [
            'anchor' => $this->anchors->capture($target, AnchorKind::File, $path->toString(), null, null, null),
            'comment' => self::snapshotComment($anchor, $targetSha),
            'location' => $location->status,
        ];
    }

    /**
     * The same kind at the located range. A line or range anchor takes the kind of the located line count, and a symbol
     * anchor whose FQN no longer names exactly one symbol becomes a line or range anchor.
     *
     * @return Anchor|null Null when the capture fails, for example for a file that is no longer UTF-8
     */
    private function capture(Review $target, Anchor $anchor, RepoPath $path, ?LineRange $range): ?Anchor
    {
        try {
            if (AnchorKind::File === $anchor->kind || null === $range) {
                return $this->anchors->capture($target, AnchorKind::File, $path->toString(), null, null, null);
            }

            if (null !== $anchor->symbol) {
                try {
                    return $this->anchors->capture($target, AnchorKind::Symbol, $path->toString(), null, null, $anchor->symbol->fqn);
                } catch (InvalidInput) {
                    // The FQN is gone or ambiguous, so the located lines carry the request.
                }
            }

            $kind = 1 === $range->lineCount() ? AnchorKind::Line : AnchorKind::Range;

            return $this->anchors->capture($target, $kind, $path->toString(), $range->startLine, $range->endLine, null);
        } catch (InvalidInput) {
            return null;
        }
    }

    /**
     * Writes request.opened, and request.commented for a first comment, for each plan whose source can still carry.
     *
     * @param array<string, Plan|OrphanReason> $plans
     *
     * @return array<string, RequestId> The new request by source request ID
     *
     * @throws LimitExceeded The requests of the target review
     */
    private function carry(Review $target, array $plans): array
    {
        $actor = $this->currentActor->get();
        $carried = [];

        $this->executor->execute($target->getId(), $actor, function () use ($target, $plans, $actor, &$carried): array {
            $review = ($this->reviews->findFresh($target->getId()) ?? throw new NotFound())->toSnapshot();
            $copies = $this->copies($target->getId());
            $decided = [];

            foreach ($plans as $id => $plan) {
                $source = $this->requests->findFresh(RequestId::fromString($id));

                if (!\is_array($plan) || isset($copies[$id]) || null === $source || !self::canCarry($source)) {
                    continue;
                }

                $open = new Open($plan['anchor'], $source->getBody(), carriedFrom: new CarriedFrom($source->getReview()->getId(), $source->getId()), requireApproval: $source->requiresApproval());
                $opened = RequestDecider::decide($open, [], $review, $actor)[0] ?? throw new \LogicException('Open decides one event.');
                $requestId = $opened->requestId ?? throw new \LogicException('request.opened has a requestId.');
                $carried[$id] = $requestId;
                $decided[] = $opened;

                if (null !== $plan['comment']) {
                    $decided[] = new DecidedEvent(new RequestCommentedEvent(CommentId::generate(), $plan['comment'], false, null), $requestId);
                }
            }

            $this->usageLimits->checkNewRequests($target->getTenantId(), $target->getId(), \count($carried));

            return $decided;
        });

        return $carried;
    }

    /**
     * @param array<string, Plan|OrphanReason> $plans
     * @param array<string, RequestId>         $carried
     */
    private function result(Review $target, Review $source, array $plans, array $carried): CarryOverResult
    {
        $copies = $this->copies($target->getId());
        $items = array_map(static function (RequestSummaryView $summary) use ($plans, $carried, $copies): CarryOverItem {
            $id = $summary->request->getId()->toString();
            $plan = $plans[$id] ?? null;

            return match (true) {
                isset($carried[$id]) => new CarryOverItem($summary, CarryOverOutcome::Carried, $carried[$id], \is_array($plan) ? $plan['location'] : null, null),
                isset($copies[$id]) => new CarryOverItem($summary, CarryOverOutcome::AlreadyCarried, $copies[$id], null, null),
                $plan instanceof OrphanReason => new CarryOverItem($summary, CarryOverOutcome::NotCarried, null, LocationStatus::Orphaned, $plan),
                default => new CarryOverItem($summary, CarryOverOutcome::NotCarried, null, null, $summary->state->status),
            };
        }, $this->requestQuery->summaries($this->sources($source->getId())));

        return new CarryOverResult($target->getId(), $source->getId(), $items);
    }

    /**
     * @return list<ChangeRequest> Fresh, in ID order
     */
    private function sources(ReviewId $sourceId): array
    {
        $sources = $this->requests->findFreshOfReview($sourceId);
        usort($sources, static fn (ChangeRequest $a, ChangeRequest $b): int => strcmp($a->getId()->toString(), $b->getId()->toString()));

        return $sources;
    }

    /**
     * @return array<string, RequestId> The carried requests of the target review by source request ID
     */
    private function copies(ReviewId $targetId): array
    {
        $copies = [];

        foreach ($this->requests->findFreshOfReview($targetId) as $request) {
            $carriedFrom = $request->getCarriedFrom();

            if (null !== $carriedFrom) {
                $copies[$carriedFrom->requestId->toString()] = $request->getId();
            }
        }

        return $copies;
    }

    /**
     * A pending suggestion stays, so it gives not_carried with its status `open`.
     */
    private static function canCarry(ChangeRequest $request): bool
    {
        return \in_array($request->getStatus(), [RequestStatus::Open, RequestStatus::Reopened], true) && SuggestionState::Pending !== $request->getSuggestionState();
    }

    private static function changedComment(CommitSha $source, CommitSha $target): string
    {
        return \sprintf('The code of this request changed between the source review commit `%s` and this review commit `%s`.', self::short($source), self::short($target));
    }

    /**
     * The fence is longer than any backtick run of the code, so the code cannot close it.
     */
    private static function snapshotComment(Anchor $anchor, CommitSha $target): string
    {
        $text = \sprintf('The code of this request was not found at this review commit `%s`, so the request is on the whole file now.', self::short($target));
        $snapshot = $anchor->snapshot;
        $range = $anchor->range;

        if (null === $snapshot || null === $range) {
            return $text;
        }

        $code = mb_substr(implode("\n", $snapshot->lines), 0, self::MAX_SNAPSHOT_CHARACTERS, 'UTF-8');
        preg_match_all('/`+/', $code, $runs);
        $fence = str_repeat('`', max(3, max(array_map(strlen(...), [...$runs[0], ''])) + 1));
        $lines = $range->startLine === $range->endLine ? "line {$range->startLine}" : "lines {$range->startLine} to {$range->endLine}";
        $omission = $snapshot->truncated ? "\n\nThe snapshot holds only the first and the last lines of the region." : '';

        return \sprintf("%s The code at the source review commit `%s`, %s of `%s`:\n\n%s\n%s\n%s%s", $text, self::short($anchor->commitSha), $lines, $anchor->path->toString(), $fence, $code, $fence, $omission);
    }

    private static function short(CommitSha $sha): string
    {
        return substr($sha->toString(), 0, self::SHORT_SHA_CHARACTERS);
    }
}
