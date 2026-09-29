<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Error\InvalidInput;
use App\Domain\Identity\RequestId;
use App\Domain\Input\TextInput;

final readonly class Release implements RequestCommand
{
    public function __construct(
        public RequestId $requestId,
        public ?string $note = null,
    ) {
        $violations = TextInput::optionalNote($note, 'note');
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }
    }
}
