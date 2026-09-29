<?php

declare(strict_types=1);

namespace App\Git;

use App\Domain\Git\CommitSha;

final class PinRefConflict extends \LogicException
{
    public function __construct(string $ref, CommitSha $pinnedSha, CommitSha $requestedSha)
    {
        parent::__construct(\sprintf('The pin %s points to %s and never moves to %s.', $ref, $pinnedSha, $requestedSha));
    }
}
