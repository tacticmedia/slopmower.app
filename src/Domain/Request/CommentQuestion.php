<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Event\Codec\PayloadReader;

/**
 * D107. An agent asks with `answer` null; a user's comment repeats the question with its answer.
 */
final readonly class CommentQuestion
{
    public function __construct(
        public string $question,
        public ?string $detail = null,
        public ?string $answer = null,
    ) {
    }

    /**
     * @return array{question: string, detail: ?string, answer: ?string}
     */
    public function toPayload(): array
    {
        return ['question' => $this->question, 'detail' => $this->detail, 'answer' => $this->answer];
    }

    public static function fromPayload(PayloadReader $reader): self
    {
        return new self($reader->string('question'), $reader->nullableString('detail'), $reader->nullableString('answer'));
    }
}
