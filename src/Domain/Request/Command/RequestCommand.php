<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Identity\RequestId;

/**
 * Text fields hold the input as written. {@see \App\Domain\Request\RequestDecider} checks them.
 */
interface RequestCommand
{
    /**
     * @return list<RequestId> The requests whose state the decider needs
     */
    public function requestIds(): array;
}
