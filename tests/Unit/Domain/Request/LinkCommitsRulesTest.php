<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Error\InvalidTransition;
use App\Domain\Event\CommitLinked;
use App\Domain\Request\Action;
use App\Domain\Request\AvailableCommit;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\CommitAvailability;
use App\Domain\Request\CommitLinkState;
use App\Domain\Request\LinkedCommit;
use App\Domain\Request\RequestDecider;
use App\Domain\Request\RequestState;
use App\Domain\Request\RequestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LinkCommitsRulesTest extends KernelTestCase
{
    use CatchesDomainErrors;

    private const string NOTE = 'One refactoring covers both.';

    #[TestDox('Skips pairs that are already linked and writes no event when nothing is new')]
    public function testSkipsLinkedPairsAndWritesNothingWhenNothingIsNew(): void
    {
        $agent = LifecycleFixtures::agent();
        $alreadyLinked = LifecycleFixtures::state(RequestStatus::InProgress, links: [new LinkedCommit(LifecycleFixtures::sha(), CommitLinkState::Pending)]);
        $notLinked = LifecycleFixtures::state(RequestStatus::InProgress, requestId: LifecycleFixtures::OTHER_REQUEST_ID);
        $bothRequests = new LinkCommits(LifecycleFixtures::sha(), [LifecycleFixtures::requestId(), LifecycleFixtures::requestId(LifecycleFixtures::OTHER_REQUEST_ID)], self::NOTE);

        $decided = RequestDecider::decide($bothRequests, [$alreadyLinked, $notLinked], LifecycleFixtures::openReview(), $agent);
        $nowLinked = LifecycleFixtures::applyDecided($notLinked, $decided, $agent);

        self::assertEquals([CommitLinked::pending(LifecycleFixtures::sha(), [LifecycleFixtures::requestId(LifecycleFixtures::OTHER_REQUEST_ID)], self::NOTE)], array_column($decided, 'event'));
        self::assertSame([null], array_column($decided, 'requestId'));
        self::assertSame([], RequestDecider::decide($bothRequests, [$alreadyLinked, $nowLinked], LifecycleFixtures::openReview(), $agent));
    }

    #[TestDox('A request that the caller does not hold gives invalid_transition and writes nothing')]
    public function testARequestThatTheCallerDoesNotHoldGivesInvalidTransition(): void
    {
        $otherAgent = LifecycleFixtures::otherAgent();
        $held = LifecycleFixtures::state(RequestStatus::InProgress);
        $heldByOtherAgent = LifecycleFixtures::state(RequestStatus::InProgress, claimedBy: $otherAgent, requestId: LifecycleFixtures::OTHER_REQUEST_ID);
        $open = LifecycleFixtures::state(RequestStatus::Open, requestId: LifecycleFixtures::THIRD_REQUEST_ID);
        $command = new LinkCommits(
            LifecycleFixtures::sha(),
            [LifecycleFixtures::requestId(), LifecycleFixtures::requestId(LifecycleFixtures::OTHER_REQUEST_ID), LifecycleFixtures::requestId(LifecycleFixtures::THIRD_REQUEST_ID)],
        );

        $error = self::domainError(InvalidTransition::class, static fn (): array => RequestDecider::decide($command, [$open, $held, $heldByOtherAgent], LifecycleFixtures::openReview(), LifecycleFixtures::agent()));

        self::assertEquals([
            'requestId' => LifecycleFixtures::requestId(LifecycleFixtures::OTHER_REQUEST_ID),
            'action' => Action::LinkCommits,
            'status' => RequestStatus::InProgress,
            'claimedBy' => $otherAgent,
            'allowedActions' => [Action::Reply],
        ], $error->details());
    }

    /**
     * @return iterable<string, array{RequestState}>
     */
    public static function linkedRequestsThatTheCallerDoesNotHold(): iterable
    {
        $links = [new LinkedCommit(LifecycleFixtures::sha(), CommitLinkState::Available)];

        yield 'verified' => [LifecycleFixtures::state(RequestStatus::Verified, links: $links)];
        yield 'in_progress held by another agent' => [LifecycleFixtures::state(RequestStatus::InProgress, claimedBy: LifecycleFixtures::otherAgent(), links: $links)];
    }

    #[DataProvider('linkedRequestsThatTheCallerDoesNotHold')]
    #[TestDox('A request that is already linked but not held by the caller gives invalid_transition')]
    public function testAnAlreadyLinkedRequestThatTheCallerDoesNotHoldGivesInvalidTransition(RequestState $alreadyLinked): void
    {
        $held = LifecycleFixtures::state(RequestStatus::InProgress, requestId: LifecycleFixtures::OTHER_REQUEST_ID);
        $command = new LinkCommits(LifecycleFixtures::sha(), [LifecycleFixtures::requestId(), LifecycleFixtures::requestId(LifecycleFixtures::OTHER_REQUEST_ID)]);

        $error = self::domainError(InvalidTransition::class, static fn (): array => RequestDecider::decide($command, [$alreadyLinked, $held], LifecycleFixtures::openReview(), LifecycleFixtures::agent()));

        self::assertEquals(
            [LifecycleFixtures::requestId(), Action::LinkCommits, $alreadyLinked->status],
            [$error->details()['requestId'], $error->details()['action'], $error->details()['status']],
        );
    }

    #[TestDox('A commit in the server clone links as available with its subject and commit time')]
    public function testAnAvailableCommitLinksAsAvailable(): void
    {
        $committedAt = new \DateTimeImmutable('2026-09-29T07:44:10Z');
        $availability = new CommitAvailability(new AvailableCommit(LifecycleFixtures::sha(), 'Use Money for invoice totals', $committedAt));

        $decided = RequestDecider::decide(
            new LinkCommits(LifecycleFixtures::sha(), [LifecycleFixtures::requestId()]),
            [LifecycleFixtures::state(RequestStatus::InProgress)],
            LifecycleFixtures::openReview(),
            LifecycleFixtures::agent(),
            $availability,
        );

        self::assertEquals(
            [CommitLinked::available(LifecycleFixtures::sha(), [LifecycleFixtures::requestId()], null, 'Use Money for invoice totals', $committedAt)],
            array_column($decided, 'event'),
        );
    }
}
