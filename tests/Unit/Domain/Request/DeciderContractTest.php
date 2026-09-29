<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Error\Forbidden;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\InvalidTransition;
use App\Domain\Event\RequestOpened;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\ReviewId;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\Command\Open;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\Command\RequestCommand;
use App\Domain\Request\Command\UnlinkCommit;
use App\Domain\Request\CommitLinkState;
use App\Domain\Request\LinkedCommit;
use App\Domain\Request\RequestDecider;
use App\Domain\Request\RequestState;
use App\Domain\Request\RequestStatus;
use App\Domain\Review\ReviewSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeciderContractTest extends KernelTestCase
{
    use CatchesDomainErrors;

    private const string OTHER_REVIEW_ID = '01K6B3A1B2C3D4E5F6G7H8J9KN';

    #[TestDox('Open by the user gives request.opened with the new request ID')]
    public function testOpenByTheUserGivesRequestOpened(): void
    {
        $batchId = BatchId::generate();
        $open = new Open(LifecycleFixtures::requestId(), LifecycleFixtures::fileAnchor(), 'Use Money.', $batchId);

        $decided = RequestDecider::decide($open, [], LifecycleFixtures::openReview(), LifecycleFixtures::user());

        self::assertEquals([LifecycleFixtures::requestId()], array_column($decided, 'requestId'));
        self::assertEquals([new RequestOpened(LifecycleFixtures::fileAnchor(), 'Use Money.', $batchId)], array_column($decided, 'event'));
    }

    /**
     * @return iterable<string, array{Actor}>
     */
    public static function actorsOtherThanTheUser(): iterable
    {
        yield 'agent' => [LifecycleFixtures::agent()];
        yield 'system' => [Actor::system()];
    }

    #[DataProvider('actorsOtherThanTheUser')]
    #[TestDox('Open by an agent or the system gives forbidden before any other check')]
    public function testOpenByAnotherActorGivesForbidden(Actor $actor): void
    {
        $atAnotherCommit = new Open(LifecycleFixtures::requestId(), LifecycleFixtures::fileAnchor(LifecycleFixtures::OTHER_COMMIT), 'Use Money.');

        self::domainError(Forbidden::class, static fn (): array => RequestDecider::decide($atAnotherCommit, [], LifecycleFixtures::closedReview(), $actor));
    }

    #[TestDox('An anchor at a commit other than the review commit gives invalid_input before review_closed')]
    public function testAnAnchorAtAnotherCommitGivesInvalidInput(): void
    {
        $atAnotherCommit = new Open(LifecycleFixtures::requestId(), LifecycleFixtures::fileAnchor(LifecycleFixtures::OTHER_COMMIT), 'Use Money.');

        $error = self::domainError(InvalidInput::class, static fn (): array => RequestDecider::decide($atAnotherCommit, [], LifecycleFixtures::closedReview(), LifecycleFixtures::user()));

        self::assertSame(['anchor.commitSha'], array_column($error->details()['violations'], 'field'));
    }

    #[TestDox('Unlinking a commit that is not linked gives invalid_input before review_closed and invalid_transition')]
    public function testUnlinkingACommitThatIsNotLinkedGivesInvalidInput(): void
    {
        $error = self::domainError(InvalidInput::class, static fn (): array => RequestDecider::decide(
            new UnlinkCommit(LifecycleFixtures::requestId(), LifecycleFixtures::sha()),
            [LifecycleFixtures::state(RequestStatus::Open)],
            LifecycleFixtures::closedReview(),
            LifecycleFixtures::otherAgent(),
        ));

        self::assertSame(['commitSha'], array_column($error->details()['violations'], 'field'));
    }

    #[TestDox('Unlinking a commit that is not linked gives invalid_input while another commit is linked')]
    public function testUnlinkingACommitThatIsNotLinkedGivesInvalidInputBesideAnotherLink(): void
    {
        $linkedToTheFix = LifecycleFixtures::state(RequestStatus::InProgress, links: [new LinkedCommit(LifecycleFixtures::sha(), CommitLinkState::Available)]);

        $error = self::domainError(InvalidInput::class, static fn (): array => RequestDecider::decide(
            new UnlinkCommit(LifecycleFixtures::requestId(), LifecycleFixtures::sha(LifecycleFixtures::OTHER_COMMIT)),
            [$linkedToTheFix],
            LifecycleFixtures::openReview(),
            LifecycleFixtures::agent(),
        ));

        self::assertSame(['commitSha'], array_column($error->details()['violations'], 'field'));
    }

    #[TestDox('Linking requests of another review gives invalid_input before review_closed')]
    public function testLinkingRequestsOfAnotherReviewGivesInvalidInput(): void
    {
        $ofOtherReview = RequestState::fromParts(LifecycleFixtures::requestId(LifecycleFixtures::OTHER_REQUEST_ID), ReviewId::fromString(self::OTHER_REVIEW_ID), RequestStatus::Open);
        $command = new LinkCommits(LifecycleFixtures::sha(), [LifecycleFixtures::requestId(), LifecycleFixtures::requestId(LifecycleFixtures::OTHER_REQUEST_ID)]);

        $error = self::domainError(InvalidInput::class, static fn (): array => RequestDecider::decide(
            $command,
            [LifecycleFixtures::state(RequestStatus::InProgress), $ofOtherReview],
            LifecycleFixtures::closedReview(),
            LifecycleFixtures::agent(),
        ));

        self::assertSame(['requestIds'], array_column($error->details()['violations'], 'field'));
    }

    #[TestDox('The system may run no request command')]
    public function testTheSystemMayRunNoRequestCommand(): void
    {
        $error = self::domainError(InvalidTransition::class, static fn (): array => RequestDecider::decide(
            new Reply(LifecycleFixtures::requestId(), CommentId::generate(), 'Found it.'),
            [LifecycleFixtures::state(RequestStatus::Open)],
            LifecycleFixtures::openReview(),
            Actor::system(),
        ));

        self::assertSame([], $error->details()['allowedActions']);
    }

    /**
     * @return iterable<string, array{RequestCommand, list<RequestState>, ReviewSnapshot}>
     */
    public static function mismatchedStates(): iterable
    {
        $open = LifecycleFixtures::state(RequestStatus::Open);
        $otherOpen = LifecycleFixtures::state(RequestStatus::Open, requestId: LifecycleFixtures::OTHER_REQUEST_ID);
        $claim = new Claim(LifecycleFixtures::requestId());
        $linkBoth = new LinkCommits(LifecycleFixtures::sha(), [LifecycleFixtures::requestId(), LifecycleFixtures::requestId(LifecycleFixtures::OTHER_REQUEST_ID)]);

        yield 'open with a state' => [new Open(LifecycleFixtures::requestId(), LifecycleFixtures::fileAnchor(), 'Use Money.'), [$open], LifecycleFixtures::openReview()];
        yield 'claim without a state' => [$claim, [], LifecycleFixtures::openReview()];
        yield 'claim with the state of another request' => [$claim, [$otherOpen], LifecycleFixtures::openReview()];
        yield 'claim with two states' => [$claim, [$open, $otherOpen], LifecycleFixtures::openReview()];
        yield 'claim in another review' => [$claim, [$open], ReviewSnapshot::open(ReviewId::fromString(self::OTHER_REVIEW_ID), LifecycleFixtures::sha(LifecycleFixtures::REVIEW_COMMIT))];
        yield 'link_commits with a missing state' => [$linkBoth, [$open], LifecycleFixtures::openReview()];
        yield 'link_commits with a state twice' => [$linkBoth, [$open, $open], LifecycleFixtures::openReview()];
    }

    /**
     * @param list<RequestState> $states
     */
    #[DataProvider('mismatchedStates')]
    #[TestDox('Refuses states that do not match the command or the review as a programming error')]
    public function testRefusesMismatchedStates(RequestCommand $command, array $states, ReviewSnapshot $review): void
    {
        $this->expectException(\LogicException::class);

        RequestDecider::decide($command, $states, $review, LifecycleFixtures::agent());
    }
}
