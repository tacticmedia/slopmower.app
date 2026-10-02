<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;

/**
 * The request is in the payload, because the `requestId` column is null for commit events.
 */
final readonly class CommitUnlinkedEvent implements DomainEvent
{
    public function __construct(
        public CommitSha $commitSha,
        public RequestId $requestId,
    ) {
    }

    public static function type(): EventType
    {
        return EventType::CommitUnlinked;
    }

    public function toPayload(): array
    {
        return [
            'commitSha' => $this->commitSha->toString(),
            'requestId' => $this->requestId->toString(),
        ];
    }

    public static function fromPayload(array $payload): self
    {
        $reader = PayloadReader::of($payload);

        return $reader->build(static fn (): self => new self(
            CommitSha::fromString($reader->string('commitSha')),
            $reader->id('requestId', RequestId::class),
        ));
    }
}
