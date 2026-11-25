<?php

declare(strict_types=1);

namespace App\Domain\Issue;

use App\Domain\Actor\ActorKind;
use App\Domain\Error\InvalidInput;

/**
 * The rules table of docs/specs/mcp.md "Issue tools" (D117). The content rules hold for every actor, the approval rules
 * bind agents.
 */
final class IssueRules
{
    private const array NEW_ISSUE_STATUSES = [IssueStatus::Draft, IssueStatus::Backlog, IssueStatus::Todo];
    private const array NEED_PROBLEM = [IssueStatus::Backlog, IssueStatus::Todo, IssueStatus::InProgress, IssueStatus::InReview, IssueStatus::Approved, IssueStatus::Done];
    private const array NEED_PLAN = [IssueStatus::InProgress, IssueStatus::InReview, IssueStatus::Approved, IssueStatus::Done];
    private const array NEED_RESULT = [IssueStatus::InReview, IssueStatus::Approved, IssueStatus::Done];
    private const array PLAN_IS_FIXED = [IssueStatus::InProgress, IssueStatus::InReview, IssueStatus::Approved];
    /** A move of an agent to one of these follows the approval mode, except done from approved. */
    private const array NEED_APPROVAL = [IssueStatus::Approved, IssueStatus::Done, IssueStatus::WontDo];
    private const string ASK_THE_USER = 'Hand the issue to the requester: set todo with waitingFor requester, and name in a comment what the user must decide.';

    /**
     * Checks the state that a write gives.
     *
     * @param IssueState|null $before     Null for a new issue
     * @param bool            $setsStatus The write is a status change or a hand-over, with $note and $reviewer
     *
     * @throws InvalidInput On the field that breaks the first rule
     */
    public static function check(?IssueState $before, IssueState $after, ApprovalMode $mode, ActorKind $actor, bool $setsStatus = false, ?string $note = null, ?string $reviewer = null): void
    {
        $violation = self::violation($before, $after, $mode, $actor, $setsStatus, $note, $reviewer);

        if (null !== $violation) {
            throw InvalidInput::field(...$violation);
        }
    }

    /**
     * The statuses that a status change of $actor can give, with a note and a reviewer. A hand-over in the status that
     * the issue has is no status change.
     *
     * @return list<IssueStatus>
     */
    public static function allowedStatuses(IssueState $issue, ApprovalMode $mode, ActorKind $actor): array
    {
        return array_values(array_filter(
            IssueStatus::cases(),
            static fn (IssueStatus $to): bool => null === self::violation($issue, $issue->withStatus($to), $mode, $actor, true, 'note', 'reviewer'),
        ));
    }

    /**
     * @return array{string, string}|null The field and the reason
     */
    private static function violation(?IssueState $before, IssueState $after, ApprovalMode $mode, ActorKind $actor, bool $setsStatus, ?string $note, ?string $reviewer): ?array
    {
        $isAgent = ActorKind::Agent === $actor;

        return self::writeViolation($before, $after, $isAgent, $setsStatus)
            ?? self::approvalViolation($before, $after, $mode, $isAgent)
            ?? ($setsStatus ? self::endViolation($before?->status, $after->status, $mode, $isAgent, $note, $reviewer) : null)
            ?? self::contentViolation($after, $mode, $isAgent);
    }

    /**
     * @return array{string, string}|null
     */
    private static function writeViolation(?IssueState $before, IssueState $after, bool $isAgent, bool $setsStatus): ?array
    {
        if (null === $before) {
            return in_array($after->status, self::NEW_ISSUE_STATUSES, true) ? null : ['status', 'A new issue is draft, backlog, or todo.'];
        }

        return match (true) {
            !$isAgent => null,
            $before->status->isEnded() => ['issue', "The issue is {$before->status->value}. An agent cannot change an ended issue. Comment on it, or link a new issue to it."],
            $setsStatus && $after->status === $before->status && $after->waitingFor === $before->waitingFor => ['status', null === $after->waitingFor
                ? "The issue is {$after->status->value} already."
                : "The issue is {$after->status->value} and waits for its {$after->waitingFor->value} already."],
            $after->plan !== $before->plan && in_array($before->status, self::PLAN_IS_FIXED, true) => ['plan', "An agent cannot change the plan of an issue in {$before->status->value}. Write the deviation into result."],
            default => null,
        };
    }

    /**
     * A plan approval that the write adds.
     *
     * @return array{string, string}|null
     */
    private static function approvalViolation(?IssueState $before, IssueState $after, ApprovalMode $mode, bool $isAgent): ?array
    {
        $isNew = null !== $after->approvedBy
            && [$after->approvedBy, $after->reviewer, $after->approvedRevision] !== [$before?->approvedBy, $before?->reviewer, $before?->approvedRevision];

        return match (true) {
            !$isNew => null,
            $isAgent && ApprovalMode::User === $mode => ['issue', 'Only the user approves a plan of this project, because its approval mode is user. '.self::ASK_THE_USER],
            self::isEmpty($after->plan) => ['plan', 'The issue has no plan.'],
            $after->approvedRevision !== $after->planRevision => ['planRevision', 'The plan changed after this revision. Read the plan again, and approve its newest revision.'],
            default => null,
        };
    }

    /**
     * A status change to approved, done, or wont_do.
     *
     * @return array{string, string}|null
     */
    private static function endViolation(?IssueStatus $from, IssueStatus $to, ApprovalMode $mode, bool $isAgent, ?string $note, ?string $reviewer): ?array
    {
        $needsApproval = $isAgent && $to !== $from && in_array($to, self::NEED_APPROVAL, true) && !(IssueStatus::Done === $to && IssueStatus::Approved === $from);
        $needsReview = $needsApproval && ApprovalMode::AgentReview === $mode;

        return match (true) {
            $needsApproval && ApprovalMode::User === $mode => ['status', IssueStatus::Done === $to
                ? 'Only the user accepts the work in this project, because its approval mode is user. Set in_review. After the user sets approved, merge the work and set done.'
                : "Only the user sets {$to->value} in this project, because its approval mode is user."],
            $needsReview && self::isEmpty($reviewer) => ['reviewer', "{$to->value} needs the name of the agent that reviewed it, because the approval mode is agent_review."],
            $needsReview && self::isEmpty($note) => ['note', "{$to->value} needs a note with the review, because the approval mode is agent_review."],
            IssueStatus::WontDo === $to && self::isEmpty($note) => ['note', 'wont_do needs a note that says why.'],
            default => null,
        };
    }

    /**
     * @return array{string, string}|null
     */
    private static function contentViolation(IssueState $issue, ApprovalMode $mode, bool $isAgent): ?array
    {
        $status = $issue->status;
        $needsPlan = in_array($status, self::NEED_PLAN, true);

        return match (true) {
            in_array($status, self::NEED_PROBLEM, true) && self::isEmpty($issue->problem) => ['problem', "An issue in {$status->value} needs a problem."],
            $needsPlan && self::isEmpty($issue->plan) => ['plan', "An issue in {$status->value} needs a plan."],
            $needsPlan && $isAgent && !self::isPlanApproved($issue, $mode) => ['plan', ApprovalMode::User === $mode
                ? "The plan needs the approval of the user before {$status->value}, because the approval mode is user. ".self::ASK_THE_USER
                : "The plan needs an approval before {$status->value}. Let another agent review it, then call approve_plan."],
            in_array($status, self::NEED_RESULT, true) && self::isEmpty($issue->result) => ['result', "An issue in {$status->value} needs a result."],
            null === WaitingFor::defaultFor($status) && null !== $issue->waitingFor => ['waitingFor', "An issue in {$status->value} waits for nobody. Send no waitingFor."],
            null !== WaitingFor::defaultFor($status) && null === $issue->waitingFor => ['waitingFor', "An issue in {$status->value} waits for its requester or its assignee."],
            ApprovalMode::User === $mode && IssuePartyKind::User !== $issue->requester => ['requester', 'The requester of an issue with the approval user is a user, because only a user approves its plan and accepts its work.'],
            default => null,
        };
    }

    private static function isPlanApproved(IssueState $issue, ApprovalMode $mode): bool
    {
        $byUser = ActorKind::User === $issue->approvedBy;

        return match ($mode) {
            ApprovalMode::Self => true,
            ApprovalMode::User => $byUser,
            ApprovalMode::AgentReview => $byUser || (ActorKind::Agent === $issue->approvedBy && null !== $issue->reviewer),
            ApprovalMode::ByIssue => throw new \LogicException('The approval of the issue binds it, never by_issue.'),
        };
    }

    private static function isEmpty(?string $text): bool
    {
        return null === $text || '' === trim($text);
    }
}
