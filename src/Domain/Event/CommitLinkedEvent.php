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
 * `available` is true when the commit was in the server clone at link time. The git data then comes with the link.
 */
final readonly class CommitLinkedEvent implements DomainEvent
{
    /**
     * @param list<RequestId> $requestIds The newly linked requests
     */
    public function __construct(
        public CommitSha $commitSha,
        public array $requestIds,
        public ?string $note,
        public bool $available,
        public ?string $subject,
        public ?\DateTimeImmutable $committedAt,
    ) {
        $violations = UniqueIds::violations($requestIds, 'requestIds');

        foreach (['subject' => $subject, 'committedAt' => $committedAt] as $field => $value) {
            if ($available && null === $value) {
                $violations[] = ['field' => $field, 'message' => 'Is required when available is true.'];
            } elseif (!$available && null !== $value) {
                $violations[] = ['field' => $field, 'message' => 'Must be null when available is false.'];
            }
        }

        if ([] !== $violations) {
            throw new InvalidInput($violations);
        }
    }

    public static function type(): EventType
    {
        return EventType::CommitLinked;
    }

    public function toPayload(): array
    {
        return [
            'commitSha' => $this->commitSha->toString(),
            'requestIds' => array_map(static fn (RequestId $id): string => $id->toString(), $this->requestIds),
            'note' => $this->note,
            'available' => $this->available,
            'subject' => $this->subject,
            'committedAt' => null === $this->committedAt ? null : TimeCodec::encode($this->committedAt),
        ];
    }

    public static function fromPayload(array $payload): self
    {
        $reader = PayloadReader::of($payload);

        return $reader->build(static fn (): self => new self(
            CommitSha::fromString($reader->string('commitSha')),
            $reader->idList('requestIds', RequestId::class),
            $reader->nullableString('note'),
            $reader->bool('available'),
            $reader->nullableString('subject'),
            $reader->nullableDateTime('committedAt'),
        ));
    }
}
