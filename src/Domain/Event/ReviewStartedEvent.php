<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;

final readonly class ReviewStartedEvent implements DomainEvent
{
    public function __construct(
        public string $title,
        public RefName $ref,
        public CommitSha $commitSha,
    ) {
    }

    public static function type(): EventType
    {
        return EventType::ReviewStarted;
    }

    public function toPayload(): array
    {
        return [
            'title' => $this->title,
            'ref' => $this->ref->toString(),
            'commitSha' => $this->commitSha->toString(),
        ];
    }

    public static function fromPayload(array $payload): self
    {
        $reader = PayloadReader::of($payload);

        return $reader->build(static fn (): self => new self(
            $reader->string('title'),
            RefName::fromString($reader->string('ref')),
            CommitSha::fromString($reader->string('commitSha')),
        ));
    }
}
