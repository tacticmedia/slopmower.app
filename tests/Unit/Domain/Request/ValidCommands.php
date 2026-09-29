<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Event\CommitLinked;
use App\Domain\Event\CommitUnlinked;
use App\Domain\Event\DecidedEvent;
use App\Domain\Event\RequestClaimed;
use App\Domain\Event\RequestCommented;
use App\Domain\Event\RequestOpened;
use App\Domain\Event\RequestReadyForReview;
use App\Domain\Event\RequestReleased;
use App\Domain\Event\RequestReopened;
use App\Domain\Event\RequestVerified;
use App\Domain\Event\RequestWontDo;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Action;
use App\Domain\Request\AvailableCommit;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Domain\Request\Command\MarkWontDo;
use App\Domain\Request\Command\Open;
use App\Domain\Request\Command\Release;
use App\Domain\Request\Command\Reopen;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\Command\RequestCommand;
use App\Domain\Request\Command\UnlinkCommit;
use App\Domain\Request\Command\Verify;
use App\Domain\Request\CommitAvailability;
use App\Domain\Request\CommitLinkState;
use App\Domain\Request\LinkedCommit;

/**
 * Each command with valid input, and the events of architecture section 3 that it gives when the state machine allows it.
 * The states hold {@see self::linkedCommit()}, so unlink_commit has a link to remove, and link_commits adds another commit.
 */
final class ValidCommands
{
    public const string OPENED_REQUEST_ID = LifecycleFixtures::THIRD_REQUEST_ID;
    public const string COMMENT_ID = '01K6C3C0MMENT0000000000001';
    public const string BODY = 'Use `Money` instead of `float` for totals.';
    public const string NOTE = 'One refactoring covers both.';
    public const string REASON = 'The totals are rounded to cents at the API boundary already.';
    public const string NEW_COMMIT_SUBJECT = 'Use Money for invoice totals';
    public const string NEW_COMMIT_COMMITTED_AT = '2026-09-29T07:44:10Z';

    public static function linkedCommit(): LinkedCommit
    {
        return new LinkedCommit(LifecycleFixtures::sha(LifecycleFixtures::FIX_COMMIT), CommitLinkState::Available);
    }

    /**
     * Both the linked commit and the commit that link_commits adds are in the server clone.
     */
    public static function availability(): CommitAvailability
    {
        return new CommitAvailability(
            new AvailableCommit(LifecycleFixtures::sha(LifecycleFixtures::FIX_COMMIT), 'Earlier fix', new \DateTimeImmutable('2026-09-29T07:30:00Z')),
            new AvailableCommit(LifecycleFixtures::sha(LifecycleFixtures::OTHER_COMMIT), self::NEW_COMMIT_SUBJECT, new \DateTimeImmutable(self::NEW_COMMIT_COMMITTED_AT)),
        );
    }

    public static function open(): Open
    {
        return new Open(LifecycleFixtures::requestId(self::OPENED_REQUEST_ID), LifecycleFixtures::fileAnchor(), self::BODY);
    }

    public static function forAction(Action $action, RequestId $requestId): RequestCommand
    {
        return match ($action) {
            Action::Reply => new Reply($requestId, CommentId::fromString(self::COMMENT_ID), self::BODY),
            Action::Claim => new Claim($requestId),
            Action::Release => new Release($requestId, self::NOTE),
            Action::LinkCommits => new LinkCommits(LifecycleFixtures::sha(LifecycleFixtures::OTHER_COMMIT), [$requestId], self::NOTE),
            Action::UnlinkCommit => new UnlinkCommit($requestId, LifecycleFixtures::sha(LifecycleFixtures::FIX_COMMIT)),
            Action::MarkReadyForReview => new MarkReadyForReview($requestId, self::NOTE),
            Action::MarkWontDo => new MarkWontDo($requestId, self::REASON),
            Action::Verify => new Verify($requestId, self::NOTE),
            Action::Reopen => new Reopen($requestId, self::NOTE),
        };
    }

    /**
     * @return list<DecidedEvent>
     */
    public static function expectedOpenEvents(): array
    {
        return [DecidedEvent::forRequest(
            LifecycleFixtures::requestId(self::OPENED_REQUEST_ID),
            new RequestOpened(LifecycleFixtures::fileAnchor(), self::BODY, null),
        )];
    }

    /**
     * @return list<DecidedEvent>
     */
    public static function expectedEvents(Action $action, RequestId $requestId): array
    {
        return [match ($action) {
            Action::Reply => DecidedEvent::forRequest($requestId, new RequestCommented(CommentId::fromString(self::COMMENT_ID), self::BODY, false, null)),
            Action::Claim => DecidedEvent::forRequest($requestId, new RequestClaimed()),
            Action::Release => DecidedEvent::forRequest($requestId, new RequestReleased(self::NOTE)),
            Action::LinkCommits => DecidedEvent::withoutRequest(CommitLinked::available(
                LifecycleFixtures::sha(LifecycleFixtures::OTHER_COMMIT),
                [$requestId],
                self::NOTE,
                self::NEW_COMMIT_SUBJECT,
                new \DateTimeImmutable(self::NEW_COMMIT_COMMITTED_AT),
            )),
            Action::UnlinkCommit => DecidedEvent::withoutRequest(new CommitUnlinked(LifecycleFixtures::sha(LifecycleFixtures::FIX_COMMIT), $requestId)),
            Action::MarkReadyForReview => DecidedEvent::forRequest($requestId, new RequestReadyForReview(self::NOTE)),
            Action::MarkWontDo => DecidedEvent::forRequest($requestId, new RequestWontDo(self::REASON)),
            Action::Verify => DecidedEvent::forRequest($requestId, new RequestVerified(self::NOTE)),
            Action::Reopen => DecidedEvent::forRequest($requestId, new RequestReopened(self::NOTE)),
        }];
    }
}
