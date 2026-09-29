<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;

final readonly class RequestCommented implements DomainEvent
{
    public function __construct(
        public CommentId $commentId,
        public string $body,
        public bool $question,
        public ?BatchId $batchId,
    ) {
    }

    public static function type(): EventType
    {
        return EventType::RequestCommented;
    }

    /**
     * @return array{commentId: string, body: string, question: bool, batchId: string|null}
     */
    public function toPayload(): array
    {
        return [
            'commentId' => $this->commentId->toString(),
            'body' => $this->body,
            'question' => $this->question,
            'batchId' => $this->batchId?->toString(),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        $reader = PayloadReader::of($payload);

        return new self(
            $reader->id('commentId', CommentId::class),
            $reader->string('body'),
            $reader->bool('question'),
            $reader->nullableId('batchId', BatchId::class),
        );
    }
}
