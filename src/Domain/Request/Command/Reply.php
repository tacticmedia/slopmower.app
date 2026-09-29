<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Error\InvalidInput;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\RequestId;
use App\Domain\Input\TextInput;

/**
 * Only an agent may ask a question. The decider checks the actor.
 */
final readonly class Reply implements RequestCommand
{
    /**
     * @param CommentId $commentId the ID of the new comment
     */
    public function __construct(
        public RequestId $requestId,
        public CommentId $commentId,
        public string $body,
        public bool $question = false,
        public ?BatchId $batchId = null,
    ) {
        $violations = TextInput::markdown($body, 'body');
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }
    }
}
