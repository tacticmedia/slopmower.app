<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Error\InvalidInput;
use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\UniqueIds;

final readonly class BatchSubmittedEvent implements DomainEvent
{
    /**
     * @param list<RequestId> $requestIds The opened and the commented requests
     * @param list<CommentId> $commentIds
     */
    public function __construct(
        public BatchId $batchId,
        public ?string $summary,
        public array $requestIds,
        public array $commentIds,
    ) {
        $violations = [
            ...UniqueIds::violations($requestIds, 'requestIds'),
            ...UniqueIds::violations($commentIds, 'commentIds', allowEmpty: true),
        ];

        if ([] !== $violations) {
            throw new InvalidInput($violations);
        }
    }

    public static function type(): EventType
    {
        return EventType::BatchSubmitted;
    }

    public function toPayload(): array
    {
        return [
            'batchId' => $this->batchId->toString(),
            'summary' => $this->summary,
            'requestIds' => array_map(static fn (RequestId $id): string => $id->toString(), $this->requestIds),
            'commentIds' => array_map(static fn (CommentId $id): string => $id->toString(), $this->commentIds),
        ];
    }

    public static function fromPayload(array $payload): self
    {
        $reader = PayloadReader::of($payload);

        return $reader->build(static fn (): self => new self(
            $reader->id('batchId', BatchId::class),
            $reader->nullableString('summary'),
            $reader->idList('requestIds', RequestId::class),
            $reader->idList('commentIds', CommentId::class),
        ));
    }
}
