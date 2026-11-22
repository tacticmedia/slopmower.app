<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Issue;

use App\Domain\Actor\ActorKind;
use App\Domain\Error\InvalidInput;
use App\Domain\Issue\ApprovalMode;
use App\Domain\Issue\IssueRules;
use App\Domain\Issue\IssueState;
use App\Domain\Issue\IssueStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The rules table of docs/specs/mcp.md "Issue tools" and the bullets under it. Each case names the field of the refusal,
 * or null when the write is allowed.
 */
final class IssueRulesTest extends KernelTestCase
{
    private const string REVISION = '01K7F3C5D6E7F8G9H0J1K2M3N4';

    #[TestDox('backlog, todo, in_progress, in_review, and done need a problem')]
    #[DataProvider('statusesWithProblem')]
    public function testProblemIsNeededFromBacklogOn(IssueStatus $status): void
    {
        $before = self::complete(IssueStatus::Draft);

        self::assertSame('problem', self::refusal($before, self::complete($status, problem: ' '), ApprovalMode::Self, setsStatus: true));
        self::assertNull(self::refusal($before, self::complete($status), ApprovalMode::Self, setsStatus: true));
        self::assertNull(self::refusal(self::complete(IssueStatus::Todo), new IssueState(IssueStatus::Draft), ApprovalMode::Self, setsStatus: true), 'draft needs none.');
    }

    /**
     * @return iterable<string, array{IssueStatus}>
     */
    public static function statusesWithProblem(): iterable
    {
        foreach ([IssueStatus::Backlog, IssueStatus::Todo, IssueStatus::InProgress, IssueStatus::InReview, IssueStatus::Done] as $status) {
            yield $status->value => [$status];
        }
    }

    #[TestDox('in_progress, in_review, and done need a plan that is approved as the approval mode says')]
    #[DataProvider('planApprovals')]
    public function testPlanIsNeededAndApprovedFromInProgressOn(ApprovalMode $mode, ?ActorKind $approvedBy, ?string $expectedField): void
    {
        $before = self::complete(IssueStatus::Todo, approvedBy: $approvedBy);

        foreach ([IssueStatus::InProgress, IssueStatus::InReview, IssueStatus::Done] as $status) {
            // The user mode refuses done from an agent before it reads the plan.
            $expected = IssueStatus::Done === $status && ApprovalMode::User === $mode ? 'status' : $expectedField;

            self::assertSame($expected, self::refusal($before, $before->withStatus($status), $mode, setsStatus: true, note: 'Reviewed.', reviewer: 'result-reviewer'), $status->value);
            self::assertSame('plan', self::refusal($before, self::complete($status, plan: null), $mode, ActorKind::User, setsStatus: true), "{$status->value} without a plan.");
        }
    }

    /**
     * @return iterable<string, array{ApprovalMode, ActorKind|null, string|null}>
     */
    public static function planApprovals(): iterable
    {
        yield 'user: no approval' => [ApprovalMode::User, null, 'plan'];
        yield 'user: approved by an agent' => [ApprovalMode::User, ActorKind::Agent, 'plan'];
        yield 'user: approved by a user' => [ApprovalMode::User, ActorKind::User, null];
        yield 'agent_review: no approval' => [ApprovalMode::AgentReview, null, 'plan'];
        yield 'agent_review: approved by an agent with a reviewer' => [ApprovalMode::AgentReview, ActorKind::Agent, null];
        yield 'agent_review: approved by a user' => [ApprovalMode::AgentReview, ActorKind::User, null];
        yield 'self: no approval' => [ApprovalMode::Self, null, null];
    }

    #[TestDox('in_review and done need a result')]
    public function testResultIsNeededFromInReviewOn(): void
    {
        $before = self::complete(IssueStatus::InProgress);

        foreach ([IssueStatus::InReview, IssueStatus::Done] as $status) {
            self::assertSame('result', self::refusal($before, self::complete($status, result: null), ApprovalMode::Self, setsStatus: true), $status->value);
            self::assertNull(self::refusal($before, self::complete($status), ApprovalMode::Self, setsStatus: true), $status->value);
        }

        self::assertNull(self::refusal(self::complete(IssueStatus::Todo), self::complete(IssueStatus::InProgress, result: null), ApprovalMode::Self, setsStatus: true), 'in_progress needs none.');
    }

    #[TestDox('done and wont_do from an agent: refused in user mode, with reviewer and note in agent_review, free in self')]
    #[DataProvider('ends')]
    public function testAnAgentEndsAnIssueAsTheModeSays(ApprovalMode $mode, ?string $note, ?string $reviewer, ?string $expectedField): void
    {
        $before = self::complete(IssueStatus::InReview, approvedBy: ActorKind::User);

        self::assertSame($expectedField, self::refusal($before, $before->withStatus(IssueStatus::Done), $mode, setsStatus: true, note: $note, reviewer: $reviewer));
    }

    /**
     * @return iterable<string, array{ApprovalMode, string|null, string|null, string|null}>
     */
    public static function ends(): iterable
    {
        yield 'user' => [ApprovalMode::User, 'Reviewed.', 'result-reviewer', 'status'];
        yield 'agent_review without a reviewer' => [ApprovalMode::AgentReview, 'Reviewed.', null, 'reviewer'];
        yield 'agent_review without a note' => [ApprovalMode::AgentReview, null, 'result-reviewer', 'note'];
        yield 'agent_review with both' => [ApprovalMode::AgentReview, 'Reviewed.', 'result-reviewer', null];
        yield 'self' => [ApprovalMode::Self, null, null, null];
    }

    #[TestDox('wont_do needs a note from every actor')]
    public function testWontDoNeedsANote(): void
    {
        $before = new IssueState(IssueStatus::Draft);
        $wontDo = $before->withStatus(IssueStatus::WontDo);

        foreach ([ActorKind::Agent, ActorKind::User] as $actor) {
            self::assertSame('note', self::refusal($before, $wontDo, ApprovalMode::Self, $actor, setsStatus: true, note: ' '), $actor->value);
            self::assertNull(self::refusal($before, $wontDo, ApprovalMode::Self, $actor, setsStatus: true, note: 'A duplicate of SM-3.'), $actor->value);
        }
    }

    #[TestDox('The content rules bind a user, the approval rules do not')]
    public function testApprovalRulesBindAgentsOnly(): void
    {
        $unapproved = self::complete(IssueStatus::Todo, approvedBy: null);

        self::assertNull(self::refusal($unapproved, $unapproved->withStatus(IssueStatus::InProgress), ApprovalMode::User, ActorKind::User, setsStatus: true));
        self::assertNull(self::refusal($unapproved, $unapproved->withStatus(IssueStatus::Done), ApprovalMode::User, ActorKind::User, setsStatus: true));
        self::assertSame('result', self::refusal($unapproved, self::complete(IssueStatus::Done, result: null), ApprovalMode::User, ActorKind::User, setsStatus: true));
    }

    #[TestDox('An agent cannot set the status that the issue has')]
    public function testAnAgentCannotSetTheSameStatus(): void
    {
        $issue = self::complete(IssueStatus::InProgress);

        self::assertSame('status', self::refusal($issue, $issue, ApprovalMode::Self, setsStatus: true));
        self::assertNull(self::refusal(self::complete(IssueStatus::Todo), $issue, ApprovalMode::Self, setsStatus: true), 'todo to in_progress is the claim.');
    }

    #[TestDox('An agent changes no field and no status of an issue in done or wont_do')]
    public function testAnAgentCannotChangeAnEndedIssue(): void
    {
        foreach ([IssueStatus::Done, IssueStatus::WontDo] as $ended) {
            $issue = self::complete($ended);

            self::assertSame('issue', self::refusal($issue, self::complete($ended, result: 'Another result.'), ApprovalMode::Self), "A field of {$ended->value}.");
            self::assertSame('issue', self::refusal($issue, $issue->withStatus(IssueStatus::Todo), ApprovalMode::Self, setsStatus: true), "The status of {$ended->value}.");
            self::assertNull(self::refusal($issue, $issue->withStatus(IssueStatus::Todo), ApprovalMode::Self, ActorKind::User, setsStatus: true), 'A user reopens it.');
        }
    }

    #[TestDox('An agent cannot change the plan in in_progress or in_review')]
    public function testAnAgentCannotChangeThePlanOfWorkInProgress(): void
    {
        foreach ([IssueStatus::InProgress, IssueStatus::InReview] as $status) {
            $issue = self::complete($status);

            self::assertSame('plan', self::refusal($issue, self::complete($status, plan: 'Another plan.', approvedBy: null), ApprovalMode::Self), $status->value);
            self::assertNull(self::refusal($issue, self::complete($status, result: 'A deviation: no new class.'), ApprovalMode::Self), "The result in {$status->value}.");
        }

        $todo = self::complete(IssueStatus::Todo);
        self::assertNull(self::refusal($todo, self::complete(IssueStatus::Todo, plan: 'Another plan.', approvedBy: null), ApprovalMode::AgentReview), 'The plan in todo.');
    }

    #[TestDox('An approval of an agent is refused in user mode, for an empty plan, and for a plan revision that is not the newest')]
    public function testApprovalOfAnAgent(): void
    {
        $unapproved = self::complete(IssueStatus::Todo, approvedBy: null);
        $approved = self::complete(IssueStatus::Todo);
        $withoutPlan = new IssueState(IssueStatus::Draft);
        $stale = new IssueState(IssueStatus::Todo, 'Problem.', 'Plan.', null, self::REVISION, ActorKind::Agent, 'plan-reviewer', '01K7F3B4C5D6E7F8G9H0J1K2M3');

        self::assertSame('issue', self::refusal($unapproved, $approved, ApprovalMode::User));
        self::assertSame('plan', self::refusal($withoutPlan, new IssueState(IssueStatus::Draft, approvedBy: ActorKind::Agent, reviewer: 'plan-reviewer', approvedRevision: self::REVISION), ApprovalMode::AgentReview));
        self::assertSame('planRevision', self::refusal($unapproved, $stale, ApprovalMode::AgentReview));
        self::assertNull(self::refusal($unapproved, $approved, ApprovalMode::AgentReview));
    }

    #[TestDox('A new issue is draft, backlog, or todo')]
    public function testNewIssueStatuses(): void
    {
        foreach (IssueStatus::cases() as $status) {
            $expected = in_array($status, [IssueStatus::Draft, IssueStatus::Backlog, IssueStatus::Todo], true) ? null : 'status';

            self::assertSame($expected, self::refusal(null, self::complete($status), ApprovalMode::Self), $status->value);
        }
    }

    #[TestDox('allowedStatuses holds the statuses that a status change can give, with a note and a reviewer')]
    public function testAllowedStatuses(): void
    {
        $planned = self::complete(IssueStatus::Todo, result: null);

        self::assertSame(
            [IssueStatus::Draft, IssueStatus::Backlog, IssueStatus::InProgress, IssueStatus::AwaitingUserInput, IssueStatus::WontDo],
            IssueRules::allowedStatuses($planned, ApprovalMode::AgentReview, ActorKind::Agent),
        );
        self::assertSame([], IssueRules::allowedStatuses(self::complete(IssueStatus::Done), ApprovalMode::Self, ActorKind::Agent));
    }

    /**
     * An issue with every section, and a plan that an agent approved with a reviewer.
     */
    private static function complete(IssueStatus $status, ?string $problem = 'Problem.', ?string $plan = 'Plan.', ?string $result = 'Result.', ?ActorKind $approvedBy = ActorKind::Agent): IssueState
    {
        return new IssueState($status, $problem, $plan, $result, self::REVISION, $approvedBy, null === $approvedBy ? null : 'plan-reviewer', null === $approvedBy ? null : self::REVISION);
    }

    /**
     * @return string|null The field of the refusal
     */
    private static function refusal(?IssueState $before, IssueState $after, ApprovalMode $mode, ActorKind $actor = ActorKind::Agent, bool $setsStatus = false, ?string $note = null, ?string $reviewer = null): ?string
    {
        try {
            IssueRules::check($before, $after, $mode, $actor, $setsStatus, $note, $reviewer);
        } catch (InvalidInput $refusal) {
            return $refusal->details()['violations'][0]['field'];
        }

        return null;
    }
}
