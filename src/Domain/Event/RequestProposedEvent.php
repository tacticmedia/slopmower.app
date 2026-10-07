<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Request\ProposedDiff;

final readonly class RequestProposedEvent implements DomainEvent
{
    public function __construct(
        public string $plan,
        public ?ProposedDiff $diff,
    ) {
    }

    public static function type(): EventType
    {
        return EventType::RequestProposed;
    }

    public function toPayload(): array
    {
        return ['plan' => $this->plan, 'diff' => $this->diff?->toString()];
    }

    public static function fromPayload(array $payload): self
    {
        $reader = PayloadReader::of($payload);
        $diff = $reader->nullableString('diff');

        return new self(
            $reader->string('plan'),
            null === $diff ? null : $reader->build(static fn (): ProposedDiff => ProposedDiff::fromString($diff)),
        );
    }
}
