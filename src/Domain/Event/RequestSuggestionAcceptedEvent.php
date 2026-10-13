<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Event\Codec\PayloadReader;

final readonly class RequestSuggestionAcceptedEvent implements DomainEvent
{
    /**
     * @param bool $requireApproval The value after the accept
     */
    public function __construct(public bool $requireApproval)
    {
    }

    public static function type(): EventType
    {
        return EventType::RequestSuggestionAccepted;
    }

    public function toPayload(): array
    {
        return ['requireApproval' => $this->requireApproval];
    }

    public static function fromPayload(array $payload): self
    {
        return new self(PayloadReader::of($payload)->bool('requireApproval'));
    }
}
