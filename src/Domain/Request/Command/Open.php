<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Anchor\Anchor;
use App\Domain\Error\InvalidInput;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\RequestId;
use App\Domain\Input\TextInput;

final readonly class Open implements RequestCommand
{
    /**
     * @param RequestId $requestId the ID of the new request
     */
    public function __construct(
        public RequestId $requestId,
        public Anchor $anchor,
        public string $body,
        public ?BatchId $batchId = null,
    ) {
        $violations = TextInput::markdown($body, 'body');
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }
    }
}
