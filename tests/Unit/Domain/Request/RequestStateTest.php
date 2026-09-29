<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Actor\ActorKind;
use App\Domain\Request\CommitLinkState;
use App\Domain\Request\LinkedCommit;
use App\Domain\Request\RequestState;
use App\Domain\Request\RequestStatus;
use App\Domain\Request\Resolution;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RequestStateTest extends KernelTestCase
{
    use ViolatedFields;

    /**
     * @return iterable<string, array{\Closure(): RequestState, list<string>}>
     */
    public static function invalidStates(): iterable
    {
        $requestId = LifecycleFixtures::requestId();
        $reviewId = LifecycleFixtures::reviewId();
        $claimedAt = new \DateTimeImmutable(LifecycleFixtures::CLAIMED_AT);
        $link = new LinkedCommit(LifecycleFixtures::sha(), CommitLinkState::Pending);

        yield 'claim fields outside in_progress' => [
            static fn (): RequestState => RequestState::fromParts($requestId, $reviewId, RequestStatus::Open, RequestStatus::Open, LifecycleFixtures::agent(), $claimedAt),
            ['statusBeforeClaim', 'claimedBy', 'claimedAt'],
        ];
        yield 'in_progress without a claim' => [
            static fn (): RequestState => RequestState::fromParts($requestId, $reviewId, RequestStatus::InProgress),
            ['statusBeforeClaim', 'claimedBy', 'claimedAt'],
        ];
        yield 'in_progress claimed by the user from ready_for_review' => [
            static fn (): RequestState => RequestState::fromParts($requestId, $reviewId, RequestStatus::InProgress, RequestStatus::ReadyForReview, LifecycleFixtures::user(), $claimedAt),
            ['statusBeforeClaim', 'claimedBy'],
        ];
        yield 'wont_do set by the system' => [
            static fn (): RequestState => RequestState::fromParts($requestId, $reviewId, RequestStatus::WontDo, wontDoSetBy: ActorKind::System),
            ['wontDoSetBy'],
        ];
        yield 'wont_do setter outside wont_do' => [
            static fn (): RequestState => RequestState::fromParts($requestId, $reviewId, RequestStatus::Verified, wontDoSetBy: ActorKind::Agent, verifiedFrom: RequestStatus::WontDo),
            ['wontDoSetBy'],
        ];
        yield 'verified from open' => [
            static fn (): RequestState => RequestState::fromParts($requestId, $reviewId, RequestStatus::Verified, verifiedFrom: RequestStatus::Open),
            ['verifiedFrom'],
        ];
        yield 'verifiedFrom outside verified' => [
            static fn (): RequestState => RequestState::fromParts($requestId, $reviewId, RequestStatus::Reopened, verifiedFrom: RequestStatus::ReadyForReview),
            ['verifiedFrom'],
        ];
        yield 'the same commit linked twice' => [
            static fn (): RequestState => RequestState::fromParts($requestId, $reviewId, RequestStatus::Open, links: [$link, $link]),
            ['links'],
        ];
        yield 'seqs after lastEventSeq, and a question without an agent event' => [
            static fn (): RequestState => RequestState::fromParts($requestId, $reviewId, RequestStatus::Open, lastUserEventSeq: 4, lastAgentEventAsksQuestion: true, lastEventSeq: 3),
            ['lastUserEventSeq', 'lastAgentEventAsksQuestion'],
        ];
        yield 'seq 0' => [
            static fn (): RequestState => RequestState::fromParts($requestId, $reviewId, RequestStatus::Open, lastEventSeq: 0),
            ['lastEventSeq'],
        ];
    }

    /**
     * @param \Closure(): RequestState $build
     * @param list<string>             $expectedFields
     */
    #[DataProvider('invalidStates')]
    #[TestDox('Rejects fields that the status does not use and seqs that break their order')]
    public function testRejectsAnInconsistentState(\Closure $build, array $expectedFields): void
    {
        self::assertSame($expectedFields, self::violatedFields($build));
    }

    #[TestDox('Takes the highest seq given as lastEventSeq when it is left out')]
    public function testDefaultsLastEventSeqToTheHighestSeq(): void
    {
        $requestId = LifecycleFixtures::requestId();
        $reviewId = LifecycleFixtures::reviewId();

        self::assertSame(1, RequestState::fromParts($requestId, $reviewId, RequestStatus::Open)->lastEventSeq);
        self::assertSame(7, RequestState::fromParts($requestId, $reviewId, RequestStatus::Open, lastUserEventSeq: 3, lastAgentEventSeq: 7)->lastEventSeq);
    }

    /**
     * @return iterable<string, array{RequestState, Resolution|null, bool}>
     */
    public static function derivedFields(): iterable
    {
        yield 'open' => [LifecycleFixtures::state(RequestStatus::Open), null, false];
        yield 'ready_for_review' => [LifecycleFixtures::state(RequestStatus::ReadyForReview), Resolution::Fixed, false];
        yield 'wont_do set by an agent' => [LifecycleFixtures::state(RequestStatus::WontDo, wontDoSetBy: ActorKind::Agent), Resolution::WontDo, false];
        yield 'wont_do set by the user' => [LifecycleFixtures::state(RequestStatus::WontDo, wontDoSetBy: ActorKind::User), Resolution::WontDo, true];
        yield 'verified from ready_for_review' => [LifecycleFixtures::state(RequestStatus::Verified, verifiedFrom: RequestStatus::ReadyForReview), Resolution::Fixed, true];
        yield 'verified from wont_do' => [LifecycleFixtures::state(RequestStatus::Verified, verifiedFrom: RequestStatus::WontDo), Resolution::WontDo, true];
    }

    #[DataProvider('derivedFields')]
    #[TestDox('Derives resolution and closed from the status and the path to verified')]
    public function testDerivesResolutionAndClosed(RequestState $state, ?Resolution $expectedResolution, bool $expectedClosed): void
    {
        self::assertSame([$expectedResolution, $expectedClosed], [$state->resolution(), $state->isClosed()]);
    }
}
