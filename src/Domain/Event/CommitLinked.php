<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Error\InvalidInput;
use App\Domain\Error\Violation;
use App\Domain\Event\Codec\PayloadDateTime;
use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Domain\Text\Utf8;

/**
 * `available` means the server clone has the commit, which gives `subject` and `committedAt`.
 */
final readonly class CommitLinked implements DomainEvent
{
    /**
     * @param list<RequestId> $requestIds the newly linked requests
     */
    private function __construct(
        public CommitSha $commitSha,
        public array $requestIds,
        public ?string $note,
        public bool $available,
        public ?string $subject,
        public ?\DateTimeImmutable $committedAt,
    ) {
    }

    /**
     * @param list<RequestId> $requestIds
     */
    public static function pending(CommitSha $commitSha, array $requestIds, ?string $note): self
    {
        return self::fromParts($commitSha, $requestIds, $note, false, null, null);
    }

    /**
     * @param list<RequestId> $requestIds
     */
    public static function available(CommitSha $commitSha, array $requestIds, ?string $note, string $subject, \DateTimeImmutable $committedAt): self
    {
        return self::fromParts($commitSha, $requestIds, $note, true, $subject, $committedAt);
    }

    /**
     * Rebuilds a stored event and checks its rules.
     *
     * @param list<RequestId> $requestIds
     */
    public static function fromParts(
        CommitSha $commitSha,
        array $requestIds,
        ?string $note,
        bool $available,
        ?string $subject,
        ?\DateTimeImmutable $committedAt,
        string $field = 'payload',
    ): self {
        $violations = [
            ...IdListViolations::nonEmptySet($requestIds, $field.'.requestIds'),
            ...PayloadDateTime::yearViolations($committedAt, $field.'.committedAt'),
        ];
        foreach (['subject' => $subject, 'committedAt' => $committedAt] as $name => $gitValue) {
            if ($available !== (null !== $gitValue)) {
                $violations[] = new Violation($field.'.'.$name, $available ? 'is required when available is true' : 'must be null when available is false');
            }
        }
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }

        return new self(
            $commitSha,
            $requestIds,
            $note,
            $available,
            null === $subject ? null : Utf8::scrub($subject),
            null === $committedAt ? null : PayloadDateTime::utc($committedAt),
        );
    }

    public static function type(): EventType
    {
        return EventType::CommitLinked;
    }

    /**
     * @return array{commitSha: string, requestIds: list<string>, note: string|null, available: bool, subject: string|null, committedAt: string|null}
     */
    public function toPayload(): array
    {
        return [
            'commitSha' => $this->commitSha->toString(),
            'requestIds' => array_map(strval(...), $this->requestIds),
            'note' => $this->note,
            'available' => $this->available,
            'subject' => $this->subject,
            'committedAt' => PayloadDateTime::format($this->committedAt),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        $reader = PayloadReader::of($payload);

        return self::fromParts(
            $reader->commitSha('commitSha'),
            $reader->idList('requestIds', RequestId::class),
            $reader->nullableString('note'),
            $reader->bool('available'),
            $reader->nullableString('subject'),
            $reader->nullableDateTime('committedAt'),
            $reader->path(),
        );
    }
}
