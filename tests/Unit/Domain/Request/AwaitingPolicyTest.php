<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Actor\ActorKind;
use App\Domain\Request\Awaiting;
use App\Domain\Request\AwaitingPolicy;
use App\Domain\Request\RequestState;
use App\Domain\Request\RequestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The awaiting rules of architecture section 6. Seq 1 is the user's request.opened.
 */
final class AwaitingPolicyTest extends KernelTestCase
{
    /**
     * @param array<string, RequestState> $states states for which this rule is the first that matches
     */
    #[DataProvider('rules')]
    #[TestDox('Rule $n gives $awaiting')]
    public function testRule(int $n, Awaiting $awaiting, array $states): void
    {
        foreach ($states as $description => $state) {
            self::assertSame($awaiting, AwaitingPolicy::of($state), $description);
        }
    }

    /**
     * @return iterable<string, array{int, Awaiting, array<string, RequestState>}>
     */
    public static function rules(): iterable
    {
        yield 'rule 1' => [1, Awaiting::None, [
            'verified from ready_for_review' => self::state(RequestStatus::Verified, 1, 11, verifiedFrom: RequestStatus::ReadyForReview),
            'verified from wont_do' => self::state(RequestStatus::Verified, 1, 11, verifiedFrom: RequestStatus::WontDo),
            'wont_do set by the user' => self::state(RequestStatus::WontDo, 1, 5, wontDoSetBy: ActorKind::User),
        ]];
        yield 'rule 2' => [2, Awaiting::Agent, [
            'ready_for_review, then a user comment' => self::state(RequestStatus::ReadyForReview, 12, 11),
            'wont_do set by an agent, then a user comment' => self::state(RequestStatus::WontDo, 12, 11, wontDoSetBy: ActorKind::Agent),
            'open without any agent event' => self::state(RequestStatus::Open, 1, null),
        ]];
        yield 'rule 3' => [3, Awaiting::User, [
            'in_progress, then an agent question' => self::state(RequestStatus::InProgress, 1, 12, asksQuestion: true),
            'open, then an agent question' => self::state(RequestStatus::Open, 1, 3, asksQuestion: true),
            'ready_for_review, then an agent question' => self::state(RequestStatus::ReadyForReview, 1, 12, asksQuestion: true),
        ]];
        yield 'rule 4' => [4, Awaiting::Agent, [
            'open, then an agent comment' => self::state(RequestStatus::Open, 1, 5),
            'reopened after ready_for_review' => self::state(RequestStatus::Reopened, 1, 11),
            'in_progress after the claim' => self::state(RequestStatus::InProgress, 1, 5),
        ]];
        yield 'rule 5' => [5, Awaiting::User, [
            'ready_for_review' => self::state(RequestStatus::ReadyForReview, 1, 11),
            'wont_do set by an agent' => self::state(RequestStatus::WontDo, 1, 11, wontDoSetBy: ActorKind::Agent),
        ]];
    }

    #[DataProvider('overlappingRules')]
    #[TestDox('An earlier rule wins over a later one')]
    public function testAnEarlierRuleWins(RequestState $state, Awaiting $earlierRuleGives): void
    {
        self::assertSame($earlierRuleGives, AwaitingPolicy::of($state));
    }

    /**
     * Each state matches both rules, and the rules give different values.
     *
     * @return iterable<string, array{RequestState, Awaiting}>
     */
    public static function overlappingRules(): iterable
    {
        yield 'rule 1 over rule 2' => [self::state(RequestStatus::Verified, 12, 11, verifiedFrom: RequestStatus::ReadyForReview), Awaiting::None];
        yield 'rule 1 over rule 3' => [self::state(RequestStatus::WontDo, 1, 11, asksQuestion: true, wontDoSetBy: ActorKind::User), Awaiting::None];
        yield 'rule 2 over rule 3' => [self::state(RequestStatus::InProgress, 13, 12, asksQuestion: true), Awaiting::Agent];
        yield 'rule 2 over rule 5' => [self::state(RequestStatus::ReadyForReview, 12, 11), Awaiting::Agent];
        yield 'rule 3 over rule 4' => [self::state(RequestStatus::InProgress, 1, 12, asksQuestion: true), Awaiting::User];
    }

    private static function state(
        RequestStatus $status,
        int $lastUserEventSeq,
        ?int $lastAgentEventSeq,
        bool $asksQuestion = false,
        ?ActorKind $wontDoSetBy = null,
        ?RequestStatus $verifiedFrom = null,
    ): RequestState {
        $inProgress = RequestStatus::InProgress === $status;

        return RequestState::fromParts(
            LifecycleFixtures::requestId(),
            LifecycleFixtures::reviewId(),
            $status,
            statusBeforeClaim: $inProgress ? RequestStatus::Open : null,
            claimedBy: $inProgress ? LifecycleFixtures::agent() : null,
            claimedAt: $inProgress ? new \DateTimeImmutable(LifecycleFixtures::CLAIMED_AT) : null,
            wontDoSetBy: $wontDoSetBy,
            verifiedFrom: $verifiedFrom,
            lastUserEventSeq: $lastUserEventSeq,
            lastAgentEventSeq: $lastAgentEventSeq,
            lastAgentEventAsksQuestion: $asksQuestion,
            lastEventSeq: max($lastUserEventSeq, $lastAgentEventSeq ?? 0) + 1,
        );
    }
}
