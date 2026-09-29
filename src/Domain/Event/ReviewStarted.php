<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;

final readonly class ReviewStarted implements DomainEvent
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

    /**
     * @return array{title: string, ref: string, commitSha: string}
     */
    public function toPayload(): array
    {
        return [
            'title' => $this->title,
            'ref' => $this->ref->toString(),
            'commitSha' => $this->commitSha->toString(),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        $reader = PayloadReader::of($payload);

        return new self(
            $reader->string('title'),
            $reader->parsed('ref', RefName::fromString(...)),
            $reader->commitSha('commitSha'),
        );
    }
}
