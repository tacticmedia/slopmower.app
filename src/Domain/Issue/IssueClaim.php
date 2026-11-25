<?php

declare(strict_types=1);

namespace App\Domain\Issue;

use App\Domain\Actor\Actor;
use App\Domain\Error\InvalidInput;
use App\Domain\Event\Codec\TimeCodec;

/**
 * The lease of one agent session on an issue (D130): issue.schema.json#/$defs/IssueClaim. The holder is the agent and
 * the session together, so two sessions of one token are two holders.
 */
final readonly class IssueClaim
{
    private const string LEASE = 'PT2H';

    /** A status change to one of these passes the issue on. */
    private const array PASSING_STATUSES = [IssueStatus::Backlog, IssueStatus::AwaitingUserInput, IssueStatus::InReview, IssueStatus::Done, IssueStatus::WontDo];

    public function __construct(
        public Actor $holder,
        public string $session,
        public \DateTimeImmutable $since,
        public \DateTimeImmutable $until,
    ) {
    }

    /**
     * The claim after a write of $agent in $session: the held claim renewed, or a new one.
     *
     * @throws InvalidInput On session, while another holder holds the issue
     */
    public static function take(?self $claim, IssueKey $issue, Actor $agent, string $session, \DateTimeImmutable $now): self
    {
        $held = self::held($claim, $now);

        if (null !== $held && !$held->isHeldBy($agent, $session)) {
            throw InvalidInput::field('session', "Session {$held->session} of {$held->holder->agentName} holds {$issue} until ".TimeCodec::encode($held->until).'. Take another issue.');
        }

        return new self($agent, $session, $held->since ?? $now, $now->add(new \DateInterval(self::LEASE)));
    }

    /**
     * A comment is never refused: it renews only a claim of its own session.
     */
    public static function renew(?self $claim, Actor $agent, string $session, \DateTimeImmutable $now): ?self
    {
        $held = self::held($claim, $now);

        return null !== $held && $held->isHeldBy($agent, $session) ? new self($agent, $session, $held->since, $now->add(new \DateInterval(self::LEASE))) : $claim;
    }

    /**
     * Null after its end.
     */
    public static function held(?self $claim, \DateTimeImmutable $now): ?self
    {
        return null !== $claim && $now < $claim->until ? $claim : null;
    }

    public static function endsWith(IssueStatus $from, IssueStatus $to): bool
    {
        return $from !== $to && (IssueStatus::InProgress === $from || \in_array($to, self::PASSING_STATUSES, true));
    }

    private function isHeldBy(Actor $agent, string $session): bool
    {
        return $this->holder->equals($agent) && $this->session === $session;
    }
}
