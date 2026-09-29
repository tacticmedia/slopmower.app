<?php

declare(strict_types=1);

namespace App\Domain\Review\Command;

use App\Domain\Error\InvalidInput;
use App\Domain\Input\TextInput;

final readonly class CloseReview implements ReviewCommand
{
    public function __construct(
        public ?string $note = null,
    ) {
        $violations = TextInput::optionalNote($note, 'note');
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }
    }
}
