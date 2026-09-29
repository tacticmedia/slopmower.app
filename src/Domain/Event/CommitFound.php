<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Error\InvalidInput;
use App\Domain\Event\Codec\PayloadDateTime;
use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;

/**
 * A fetch found a pending linked commit. The system actor writes one per review.
 */
final readonly class CommitFound implements DomainEvent
{
    /**
     * @param list<RequestId> $requestIds the requests of the review linked to the commit
     */
    private function __construct(
        public CommitSha $commitSha,
        public array $requestIds,
        public string $subject,
        public \DateTimeImmutable $committedAt,
    ) {
    }

    /**
     * @param list<RequestId> $requestIds
     */
    public static function of(CommitSha $commitSha, array $requestIds, string $subject, \DateTimeImmutable $committedAt, string $field = 'payload'): self
    {
        $violations = IdListViolations::nonEmptySet($requestIds, $field.'.requestIds');
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }

        return new self($commitSha, $requestIds, $subject, $committedAt);
    }

    public static function type(): EventType
    {
        return EventType::CommitFound;
    }

    /**
     * @return array{commitSha: string, requestIds: list<string>, subject: string, committedAt: string}
     */
    public function toPayload(): array
    {
        return [
            'commitSha' => $this->commitSha->toString(),
            'requestIds' => array_map(strval(...), $this->requestIds),
            'subject' => $this->subject,
            'committedAt' => PayloadDateTime::format($this->committedAt),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        $reader = PayloadReader::of($payload);

        return self::of(
            $reader->commitSha('commitSha'),
            $reader->idList('requestIds', RequestId::class),
            $reader->string('subject'),
            $reader->dateTime('committedAt'),
            $reader->path(),
        );
    }
}
