<?php

declare(strict_types=1);

namespace App\Doctrine\Embeddable;

use App\Domain\Identity\UserId;
use App\Domain\Issue\IssueParty;
use App\Domain\Issue\IssuePartyKind;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final class IssuePartyEmbeddable
{
    #[ORM\Column(enumType: IssuePartyKind::class)]
    private IssuePartyKind $kind;

    #[ORM\Column(type: UserId::class, nullable: true)]
    private ?UserId $userId;

    private function __construct(IssueParty $party)
    {
        $this->kind = $party->kind;
        $this->userId = $party->userId;
    }

    public static function of(IssueParty $party): self
    {
        return new self($party);
    }

    /**
     * @throws \UnexpectedValueException For a user without its ID
     */
    public function toParty(): IssueParty
    {
        return IssuePartyKind::Agent === $this->kind
            ? IssueParty::agent()
            : IssueParty::user($this->userId ?? throw new \UnexpectedValueException('Corrupt party columns: a user without its ID.'));
    }
}
