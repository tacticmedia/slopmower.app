<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Error\InvalidInput;
use App\Domain\Identity\RequestId;
use App\Domain\Input\TextInput;

final readonly class MarkReadyForReview implements RequestCommand
{
    public function __construct(
        public RequestId $requestId,
        public string $note,
    ) {
        $violations = TextInput::markdown($note, 'note');
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }
    }
}
