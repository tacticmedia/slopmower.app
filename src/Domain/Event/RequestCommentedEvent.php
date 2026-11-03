<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Event\Codec\PayloadReader;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;
use App\Domain\Request\CommentQuestion;

final readonly class RequestCommentedEvent implements DomainEvent
{
    /**
     * @param list<CommentQuestion> $questions
     */
    public function __construct(
        public CommentId $commentId,
        public string $body,
        public bool $question,
        public ?BatchId $batchId,
        public array $questions = [],
    ) {
    }

    public static function type(): EventType
    {
        return EventType::RequestCommented;
    }

    public function toPayload(): array
    {
        return [
            'commentId' => $this->commentId->toString(),
            'body' => $this->body,
            'question' => $this->question,
            'batchId' => $this->batchId?->toString(),
            'questions' => array_map(static fn (CommentQuestion $question): array => $question->toPayload(), $this->questions),
        ];
    }

    public static function fromPayload(array $payload): self
    {
        $reader = PayloadReader::of($payload);

        return new self(
            $reader->id('commentId', CommentId::class),
            $reader->string('body'),
            $reader->bool('question'),
            $reader->nullableId('batchId', BatchId::class),
            array_key_exists('questions', $payload) ? array_map(CommentQuestion::fromPayload(...), $reader->objectList('questions')) : [],
        );
    }
}
