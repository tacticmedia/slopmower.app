<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Review;

use App\Domain\Actor\Actor;
use App\Domain\Error\Forbidden;
use App\Domain\Event\RequestClaimed;
use App\Domain\Event\ReviewClosed;
use App\Domain\Event\ReviewStarted;
use App\Domain\Git\RefName;
use App\Domain\Review\Command\CloseReview;
use App\Domain\Review\Command\ReviewCommand;
use App\Domain\Review\Command\StartReview;
use App\Domain\Review\ReviewDecider;
use App\Domain\Review\ReviewReducer;
use App\Domain\Review\ReviewSnapshot;
use App\Domain\Review\ReviewState;
use App\Tests\Unit\Domain\Request\CatchesDomainErrors;
use App\Tests\Unit\Domain\Request\LifecycleFixtures;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReviewLifecycleTest extends KernelTestCase
{
    use CatchesDomainErrors;
    use ViolatedFields;

    #[TestDox('Start and close by the user give review.started and review.closed')]
    public function testStartAndCloseByTheUser(): void
    {
        $start = new StartReview('main @ 79f8595a4c1e', RefName::fromString('main'), LifecycleFixtures::sha(LifecycleFixtures::REVIEW_COMMIT));

        $started = ReviewDecider::decide($start, null, LifecycleFixtures::user());
        $closed = ReviewDecider::decide(new CloseReview('Shipped in 2.4'), LifecycleFixtures::openReview(), LifecycleFixtures::user());

        self::assertEquals([new ReviewStarted('main @ 79f8595a4c1e', RefName::fromString('main'), LifecycleFixtures::sha(LifecycleFixtures::REVIEW_COMMIT))], array_column($started, 'event'));
        self::assertEquals([new ReviewClosed('Shipped in 2.4')], array_column($closed, 'event'));
        self::assertSame([null, null], [...array_column($started, 'requestId'), ...array_column($closed, 'requestId')]);
    }

    /**
     * @return iterable<string, array{ReviewCommand, ReviewSnapshot|null, Actor}>
     */
    public static function commandsByOtherActors(): iterable
    {
        $start = new StartReview('main', RefName::fromString('main'), LifecycleFixtures::sha(LifecycleFixtures::REVIEW_COMMIT));

        yield 'start by an agent' => [$start, null, LifecycleFixtures::agent()];
        yield 'close by an agent' => [new CloseReview(), LifecycleFixtures::closedReview(), LifecycleFixtures::agent()];
        yield 'close by the system' => [new CloseReview(), LifecycleFixtures::openReview(), Actor::system()];
    }

    #[DataProvider('commandsByOtherActors')]
    #[TestDox('Start and close by an agent or the system give forbidden')]
    public function testOtherActorsGetForbidden(ReviewCommand $command, ?ReviewSnapshot $review, Actor $actor): void
    {
        self::domainError(Forbidden::class, static fn (): array => ReviewDecider::decide($command, $review, $actor));
    }

    #[TestDox('The reducer opens the review at review.started, closes it at review.closed, and ignores other events')]
    public function testTheReducerFollowsTheReviewEvents(): void
    {
        $user = LifecycleFixtures::user();
        $events = [
            LifecycleFixtures::recorded(1, new ReviewStarted('main', RefName::fromString('main'), LifecycleFixtures::sha(LifecycleFixtures::REVIEW_COMMIT)), $user),
            LifecycleFixtures::recorded(2, new RequestClaimed(), LifecycleFixtures::agent()),
            LifecycleFixtures::recorded(3, new ReviewClosed('Shipped in 2.4'), $user, occurredAt: '2026-09-30T16:30:00Z'),
        ];

        $review = null;
        $states = [];
        foreach ($events as $recorded) {
            $review = ReviewReducer::apply($review, $recorded);
            $states[] = $review->state();
        }

        self::assertSame([ReviewState::Open, ReviewState::Open, ReviewState::Closed], $states);
        self::assertEquals(LifecycleFixtures::closedReview(), $review);
    }

    #[TestDox('The reducer refuses a review that does not start with review.started or starts twice')]
    public function testTheReducerRefusesEventsOutOfLifecycleOrder(): void
    {
        $started = LifecycleFixtures::recorded(1, new ReviewStarted('main', RefName::fromString('main'), LifecycleFixtures::sha(LifecycleFixtures::REVIEW_COMMIT)), LifecycleFixtures::user());

        foreach ([[null, LifecycleFixtures::recorded(1, new ReviewClosed(null), LifecycleFixtures::user())], [LifecycleFixtures::openReview(), $started]] as [$review, $recorded]) {
            try {
                ReviewReducer::apply($review, $recorded);
                self::fail('The reducer accepted '.$recorded->type->value.'.');
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[TestDox('A snapshot needs closedBy exactly when closedAt is set, and closeNote only then')]
    public function testSnapshotClosingFieldsGoTogether(): void
    {
        $reviewId = LifecycleFixtures::reviewId();
        $sha = LifecycleFixtures::sha(LifecycleFixtures::REVIEW_COMMIT);

        self::assertSame(['closedBy'], self::violatedFields(static fn (): ReviewSnapshot => ReviewSnapshot::fromParts($reviewId, $sha, new \DateTimeImmutable())));
        self::assertSame(['closedBy', 'closeNote'], self::violatedFields(static fn (): ReviewSnapshot => ReviewSnapshot::fromParts($reviewId, $sha, closedBy: LifecycleFixtures::user(), closeNote: 'x')));
        self::assertEquals(LifecycleFixtures::closedReview(), ReviewSnapshot::fromParts($reviewId, $sha, new \DateTimeImmutable('2026-09-30T16:30:00Z'), LifecycleFixtures::user(), 'Shipped in 2.4'));
    }
}
