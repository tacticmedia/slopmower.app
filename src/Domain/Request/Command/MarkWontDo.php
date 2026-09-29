<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Error\InvalidInput;
use App\Domain\Event\RequestWontDo;
use App\Domain\Identity\RequestId;
use App\Domain\Input\TextInput;

final readonly class MarkWontDo implements RequestCommand
{
    public function __construct(
        public RequestId $requestId,
        public string $reason,
    ) {
        $violations = TextInput::violations($reason, 'reason', minTrimmedCharacters: RequestWontDo::MIN_TRIMMED_REASON_LENGTH);
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }
    }
}
