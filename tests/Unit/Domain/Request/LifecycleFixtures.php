<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Actor\ActorKind;
use App\Domain\Anchor\Anchor;
use App\Domain\Event\DecidedEvent;
use App\Domain\Event\DomainEvent;
use App\Domain\Event\RecordedEvent;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Identity\UserId;
use App\Domain\Request\LinkedCommit;
use App\Domain\Request\RequestReducer;
use App\Domain\Request\RequestState;
use App\Domain\Request\RequestStatus;
use App\Domain\Review\ReviewSnapshot;

/**
 * The docs/specs fixture story: the user reviews acme/billing, and the agent `claude-code laptop` works on it.
 */
final class LifecycleFixtures
{
    public const string REVIEW_ID = '01K6B3A1B2C3D4E5F6G7H8J9KM';
    public const string REQUEST_ID = '01K6B3P2A9QRSTVWXYZ0123456';
    public const string OTHER_REQUEST_ID = '01K6B3P2A9QRSTVWXYZ0123457';
    public const string THIRD_REQUEST_ID = '01K6B3P2A9QRSTVWXYZ0123458';
    public const string USER_ID = '01K6AZZ9Y8X7W6V5T4S3R2Q1P0';
    public const string REVIEW_COMMIT = '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091';
    public const string FIX_COMMIT = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    public const string OTHER_COMMIT = 'e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e';
    public const string THIRD_COMMIT = '0123456789abcdef0123456789abcdef01234567';
    public const string AGENT_NAME = 'claude-code laptop';
    public const string OTHER_AGENT_NAME = 'codex desktop';
    public const string CLAIMED_AT = '2026-09-29T07:40:00Z';

    public static function reviewId(): ReviewId
    {
        return ReviewId::fromString(self::REVIEW_ID);
    }

    public static function requestId(string $requestId = self::REQUEST_ID): RequestId
    {
        return RequestId::fromString($requestId);
    }

    public static function sha(string $commitSha = self::FIX_COMMIT): CommitSha
    {
        return CommitSha::fromString($commitSha);
    }

    public static function user(): Actor
    {
        return Actor::user(UserId::fromString(self::USER_ID));
    }

    public static function agent(): Actor
    {
        return Actor::agent(UserId::fromString(self::USER_ID), self::AGENT_NAME);
    }

    public static function otherAgent(): Actor
    {
        return Actor::agent(UserId::fromString(self::USER_ID), self::OTHER_AGENT_NAME);
    }

    public static function openReview(): ReviewSnapshot
    {
        return ReviewSnapshot::open(self::reviewId(), self::sha(self::REVIEW_COMMIT));
    }

    public static function closedReview(): ReviewSnapshot
    {
        return ReviewSnapshot::closed(self::reviewId(), self::sha(self::REVIEW_COMMIT), new \DateTimeImmutable('2026-09-30T16:30:00Z'), self::user(), 'Shipped in 2.4');
    }

    public static function fileAnchor(string $commitSha = self::REVIEW_COMMIT): Anchor
    {
        return Anchor::file(RepoPath::fromString('src/Billing/InvoiceTotals.php'), self::sha($commitSha), BlobSha::fromString('3b18e512dba79e4c8300dd08aeb37f8e728b8dad'));
    }

    /**
     * An in_progress state is claimed from open by {@see self::agent()} unless told otherwise.
     * A wont_do state is set by an agent unless told otherwise. A verified state comes from ready_for_review.
     *
     * @param list<LinkedCommit> $links
     */
    public static function state(
        RequestStatus $status,
        ?Actor $claimedBy = null,
        ActorKind $wontDoSetBy = ActorKind::Agent,
        array $links = [],
        RequestStatus $statusBeforeClaim = RequestStatus::Open,
        string $requestId = self::REQUEST_ID,
        RequestStatus $verifiedFrom = RequestStatus::ReadyForReview,
    ): RequestState {
        $inProgress = RequestStatus::InProgress === $status;

        return RequestState::fromParts(
            self::requestId($requestId),
            self::reviewId(),
            $status,
            statusBeforeClaim: $inProgress ? $statusBeforeClaim : null,
            claimedBy: $inProgress ? $claimedBy ?? self::agent() : null,
            claimedAt: $inProgress ? new \DateTimeImmutable(self::CLAIMED_AT) : null,
            wontDoSetBy: RequestStatus::WontDo === $status ? $wontDoSetBy : null,
            verifiedFrom: RequestStatus::Verified === $status ? $verifiedFrom : null,
            links: $links,
            lastUserEventSeq: 1,
            lastEventSeq: 10,
        );
    }

    public static function recorded(int $seq, DomainEvent $event, Actor $actor, ?RequestId $requestId = null, string $occurredAt = '2026-09-29T08:00:00Z'): RecordedEvent
    {
        return new RecordedEvent(
            EventId::generate(),
            TenantId::fromString(self::USER_ID),
            $seq,
            self::reviewId(),
            $event::type()->hasRequestId() ? $requestId ?? self::requestId() : null,
            $event::type(),
            $actor,
            new \DateTimeImmutable($occurredAt),
            $event,
        );
    }

    /**
     * Records the decided events after the last seq of the state and reduces them into it.
     *
     * @param list<DecidedEvent> $decidedEvents
     */
    public static function applyDecided(RequestState $state, array $decidedEvents, Actor $actor): RequestState
    {
        foreach ($decidedEvents as $decidedEvent) {
            $state = RequestReducer::apply($state, self::recorded($state->lastEventSeq + 1, $decidedEvent->event, $actor, $decidedEvent->requestId));
        }

        return $state;
    }
}
