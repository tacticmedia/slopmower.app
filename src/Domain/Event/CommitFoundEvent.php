<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Error\InvalidInput;
use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Event\Codec\TimeCodec;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\UniqueIds;

/**
 * A pending linked commit arrived in the server clone. One event per review, by the system actor.
 */
final readonly class CommitFoundEvent implements DomainEvent
{
    /**
     * @param list<RequestId> $requestIds The requests of the review linked to the commit
     */
    public function __construct(
        public CommitSha $commitSha,
        public array $requestIds,
        public string $subject,
        public \DateTimeImmutable $committedAt,
    ) {
        $violations = UniqueIds::violations($requestIds, 'requestIds');

        if ([] !== $violations) {
            throw new InvalidInput($violations);
        }
    }

    public static function type(): EventType
    {
        return EventType::CommitFound;
    }

    public function toPayload(): array
    {
        return [
            'commitSha' => $this->commitSha->toString(),
            'requestIds' => array_map(static fn (RequestId $id): string => $id->toString(), $this->requestIds),
            'subject' => $this->subject,
            'committedAt' => TimeCodec::encode($this->committedAt),
        ];
    }

    public static function fromPayload(array $payload): self
    {
        $reader = PayloadReader::of($payload);

        return $reader->build(static fn (): self => new self(
            CommitSha::fromString($reader->string('commitSha')),
            $reader->idList('requestIds', RequestId::class),
            $reader->string('subject'),
            $reader->dateTime('committedAt'),
        ));
    }
}
