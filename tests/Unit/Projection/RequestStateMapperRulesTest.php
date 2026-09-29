<?php

declare(strict_types=1);

namespace App\Tests\Unit\Projection;

use App\Domain\Actor\Actor;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Identity\UserId;
use App\Domain\Request\Awaiting;
use App\Domain\Request\CommitLinkState;
use App\Domain\Request\LinkedCommit;
use App\Domain\Request\RequestState;
use App\Domain\Request\RequestStatus;
use App\Entity\ChangeRequest;
use App\Entity\CommitLink;
use App\Projection\RequestStateMapper;
use App\Tests\Support\Fixtures\ReviewData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RequestStateMapperRulesTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{\Closure(ChangeRequest): RequestState}>
     */
    public static function statesOfAnotherRequest(): iterable
    {
        yield 'another request ID' => [static fn (ChangeRequest $request): RequestState => RequestState::opened(RequestId::generate(), $request->getReviewId(), 2)];
        yield 'another review ID' => [static fn (ChangeRequest $request): RequestState => RequestState::opened($request->getId(), ReviewId::generate(), 2)];
    }

    /**
     * @param \Closure(ChangeRequest): RequestState $stateOf
     */
    #[DataProvider('statesOfAnotherRequest')]
    #[TestDox('apply() refuses the state of another request')]
    public function testApplyRefusesTheStateOfAnotherRequest(\Closure $stateOf): void
    {
        $request = self::request();

        $this->expectException(\LogicException::class);

        RequestStateMapper::apply($stateOf($request), $request, ReviewData::time());
    }

    #[TestDox('toState() refuses a commit link of another request')]
    public function testToStateRefusesACommitLinkOfAnotherRequest(): void
    {
        $request = self::request();
        $otherRequest = ReviewData::request($request->getReview(), self::user());

        $this->expectException(\LogicException::class);

        RequestStateMapper::toState($request, [self::pendingLink($otherRequest, ReviewData::FIX_COMMIT, 3)]);
    }

    #[TestDox('toState() orders the links by the seq of their commit.linked event')]
    public function testToStateOrdersTheLinksByTheirLinkSeq(): void
    {
        $request = self::request();
        $links = [
            self::pendingLink($request, ReviewData::OTHER_COMMIT, 5),
            self::pendingLink($request, ReviewData::REVIEW_COMMIT, 3),
            self::pendingLink($request, ReviewData::FIX_COMMIT, 4),
        ];

        self::assertEquals(
            [ReviewData::REVIEW_COMMIT, ReviewData::FIX_COMMIT, ReviewData::OTHER_COMMIT],
            array_map(static fn (LinkedCommit $link): string => $link->commitSha->toString(), RequestStateMapper::toState($request, $links)->links),
        );
    }

    #[TestDox('toState() refuses stored awaiting or resolution that the state does not give')]
    public function testToStateRefusesAwaitingThatTheStateDoesNotGive(): void
    {
        $request = self::request();
        $request->changeLifecycle(
            status: RequestStatus::Open,
            statusBeforeClaim: null,
            claimedBy: null,
            claimedAt: null,
            wontDoSetBy: null,
            verifiedFrom: null,
            lastUserEventSeq: 2,
            lastAgentEventSeq: null,
            lastAgentEventAsksQuestion: false,
            awaiting: Awaiting::User,
            resolution: null,
            lastEventSeq: 2,
            updatedAt: ReviewData::time(),
        );

        $this->expectException(\LogicException::class);

        RequestStateMapper::toState($request, []);
    }

    #[TestDox('A new request maps to an open state that awaits the agent')]
    public function testANewRequestMapsToAnOpenStateThatAwaitsTheAgent(): void
    {
        $request = self::request();
        $state = RequestStateMapper::toState($request, []);

        self::assertEquals(RequestState::fromParts($request->getId(), $request->getReviewId(), RequestStatus::Open, lastEventSeq: 2), $state);
        self::assertSame(Awaiting::Agent, $request->getAwaiting());
    }

    private static function request(): ChangeRequest
    {
        return ReviewData::request(ReviewData::review(ReviewData::gitHubRepository(TenantId::generate()), self::user()), self::user());
    }

    private static function pendingLink(ChangeRequest $request, string $commitSha, int $linkedSeq): CommitLink
    {
        $link = CommitLink::pending($request, CommitSha::fromString($commitSha), Actor::agent(UserId::generate(), 'claude-code laptop'), ReviewData::time(), $linkedSeq, null);
        self::assertSame(CommitLinkState::Pending, $link->getState());

        return $link;
    }

    private static function user(): Actor
    {
        return Actor::user(UserId::fromString('01K6AZZ9Y8X7W6V5T4S3R2Q1P0'));
    }
}
