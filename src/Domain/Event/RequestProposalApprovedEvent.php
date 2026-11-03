<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Identity\EventId;

final readonly class RequestProposalApprovedEvent implements DomainEvent
{
    public function __construct(
        public EventId $proposalEventId,
        public ?string $note,
    ) {
    }

    public static function type(): EventType
    {
        return EventType::RequestProposalApproved;
    }

    public function toPayload(): array
    {
        return ['proposalEventId' => $this->proposalEventId->toString(), 'note' => $this->note];
    }

    public static function fromPayload(array $payload): self
    {
        $reader = PayloadReader::of($payload);

        return new self($reader->id('proposalEventId', EventId::class), $reader->nullableString('note'));
    }
}
