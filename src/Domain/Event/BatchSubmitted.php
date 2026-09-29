<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Error\InvalidInput;
use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\RequestId;

final readonly class BatchSubmitted implements DomainEvent
{
    /**
     * @param list<RequestId> $requestIds the opened and the commented requests
     * @param list<CommentId> $commentIds
     */
    private function __construct(
        public BatchId $batchId,
        public ?string $summary,
        public array $requestIds,
        public array $commentIds,
    ) {
    }

    /**
     * @param list<RequestId> $requestIds
     * @param list<CommentId> $commentIds
     */
    public static function of(BatchId $batchId, ?string $summary, array $requestIds, array $commentIds, string $field = 'payload'): self
    {
        $violations = [
            ...IdListViolations::set($requestIds, $field.'.requestIds'),
            ...IdListViolations::set($commentIds, $field.'.commentIds'),
        ];
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }

        return new self($batchId, $summary, $requestIds, $commentIds);
    }

    public static function type(): EventType
    {
        return EventType::BatchSubmitted;
    }

    /**
     * @return array{batchId: string, summary: string|null, requestIds: list<string>, commentIds: list<string>}
     */
    public function toPayload(): array
    {
        return [
            'batchId' => $this->batchId->toString(),
            'summary' => $this->summary,
            'requestIds' => array_map(strval(...), $this->requestIds),
            'commentIds' => array_map(strval(...), $this->commentIds),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        $reader = PayloadReader::of($payload);

        return self::of(
            $reader->id('batchId', BatchId::class),
            $reader->nullableString('summary'),
            $reader->idList('requestIds', RequestId::class),
            $reader->idList('commentIds', CommentId::class),
            $reader->path(),
        );
    }
}
