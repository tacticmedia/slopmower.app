<?php

declare(strict_types=1);

namespace App\Domain\Issue;

use App\Domain\Actor\Actor;
use App\Domain\Identity\UserId;

/**
 * The requester or the assignee of an issue (D134): issue.schema.json#/$defs/IssueParty.
 */
final readonly class IssueParty
{
    private function __construct(
        public IssuePartyKind $kind,
        public ?UserId $userId,
    ) {
    }

    public static function user(UserId $userId): self
    {
        return new self(IssuePartyKind::User, $userId);
    }

    public static function agent(): self
    {
        return new self(IssuePartyKind::Agent, null);
    }

    /**
     * An input party: user is the user of the caller.
     */
    public static function forCaller(IssuePartyKind $kind, Actor $caller): self
    {
        return IssuePartyKind::Agent === $kind ? self::agent() : self::user($caller->userId ?? throw new \LogicException('The system names no party.'));
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->userId?->toString() === $other->userId?->toString();
    }
}
