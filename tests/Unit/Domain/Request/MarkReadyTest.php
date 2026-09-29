<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Error\CommitsNotAvailable;
use App\Domain\Error\DomainError;
use App\Domain\Error\InvalidTransition;
use App\Domain\Error\ReviewClosed;
use App\Domain\Event\RequestReadyForReview;
use App\Domain\Request\AvailableCommit;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Domain\Request\CommitAvailability;
use App\Domain\Request\CommitLinkState;
use App\Domain\Request\LinkedCommit;
use App\Domain\Request\RequestDecider;
use App\Domain\Request\RequestStatus;
use App\Domain\Review\ReviewSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MarkReadyTest extends KernelTestCase
{
    use CatchesDomainErrors;

    private const string NOTE = 'Totals use Money now.';

    #[TestDox('Allows zero links')]
    public function testAllowsZeroLinks(): void
    {
        self::assertEquals([new RequestReadyForReview(self::NOTE)], self::markReady([]));
    }

    #[TestDox('A pending link gives commits_not_available with the pending SHAs')]
    public function testAPendingLinkGivesCommitsNotAvailable(): void
    {
        $error = self::domainError(CommitsNotAvailable::class, static fn (): array => self::markReady([
            new LinkedCommit(LifecycleFixtures::sha(LifecycleFixtures::FIX_COMMIT), CommitLinkState::Available),
            new LinkedCommit(LifecycleFixtures::sha(LifecycleFixtures::OTHER_COMMIT), CommitLinkState::Pending),
            new LinkedCommit(LifecycleFixtures::sha(LifecycleFixtures::THIRD_COMMIT), CommitLinkState::Pending),
        ]));

        self::assertEquals(
            ['pendingCommits' => [LifecycleFixtures::sha(LifecycleFixtures::OTHER_COMMIT), LifecycleFixtures::sha(LifecycleFixtures::THIRD_COMMIT)]],
            $error->details(),
        );
    }

    #[TestDox('All links available gives ready_for_review')]
    public function testAllLinksAvailableGivesReadyForReview(): void
    {
        self::assertEquals([new RequestReadyForReview(self::NOTE)], self::markReady([
            new LinkedCommit(LifecycleFixtures::sha(LifecycleFixtures::FIX_COMMIT), CommitLinkState::Available),
            new LinkedCommit(LifecycleFixtures::sha(LifecycleFixtures::OTHER_COMMIT), CommitLinkState::Available),
        ]));
    }

    #[TestDox('A pending link counts as available when the availability after the fetch lists its commit')]
    public function testThePassedAvailabilityResolvesAPendingLink(): void
    {
        $links = [
            new LinkedCommit(LifecycleFixtures::sha(LifecycleFixtures::OTHER_COMMIT), CommitLinkState::Pending),
            new LinkedCommit(LifecycleFixtures::sha(LifecycleFixtures::THIRD_COMMIT), CommitLinkState::Pending),
        ];
        $fetched = new AvailableCommit(LifecycleFixtures::sha(LifecycleFixtures::OTHER_COMMIT), 'Use Money', new \DateTimeImmutable('2026-09-29T07:44:10Z'));

        $error = self::domainError(CommitsNotAvailable::class, static fn (): array => self::markReady($links, new CommitAvailability($fetched)));

        self::assertEquals(['pendingCommits' => [LifecycleFixtures::sha(LifecycleFixtures::THIRD_COMMIT)]], $error->details());
    }

    /**
     * @return iterable<string, array{Actor, ReviewSnapshot, class-string<DomainError>}>
     */
    public static function earlierChecks(): iterable
    {
        yield 'another agent gives invalid_transition' => [LifecycleFixtures::otherAgent(), LifecycleFixtures::openReview(), InvalidTransition::class];
        yield 'the holder on a closed review gives review_closed' => [LifecycleFixtures::agent(), LifecycleFixtures::closedReview(), ReviewClosed::class];
    }

    /**
     * @param class-string<DomainError> $expectedError
     */
    #[DataProvider('earlierChecks')]
    #[TestDox('A pending link gives commits_not_available only after review_closed and invalid_transition')]
    public function testAPendingLinkIsCheckedAfterReviewClosedAndInvalidTransition(Actor $actor, ReviewSnapshot $review, string $expectedError): void
    {
        $pendingLink = new LinkedCommit(LifecycleFixtures::sha(LifecycleFixtures::OTHER_COMMIT), CommitLinkState::Pending);

        self::domainError($expectedError, static fn (): array => RequestDecider::decide(
            new MarkReadyForReview(LifecycleFixtures::requestId(), self::NOTE),
            [LifecycleFixtures::state(RequestStatus::InProgress, links: [$pendingLink])],
            $review,
            $actor,
        ));
    }

    /**
     * @param list<LinkedCommit> $links
     *
     * @return list<\App\Domain\Event\DomainEvent>
     */
    private static function markReady(array $links, CommitAvailability $availability = new CommitAvailability()): array
    {
        $decided = RequestDecider::decide(
            new MarkReadyForReview(LifecycleFixtures::requestId(), self::NOTE),
            [LifecycleFixtures::state(RequestStatus::InProgress, links: $links)],
            LifecycleFixtures::openReview(),
            LifecycleFixtures::agent(),
            $availability,
        );

        return array_column($decided, 'event');
    }
}
