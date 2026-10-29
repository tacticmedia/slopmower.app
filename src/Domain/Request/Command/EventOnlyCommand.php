<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Identity\RequestId;

/**
 * A command on one request whose whole effect is its events, so {@see \App\Application\RequestCommands::execute()} runs it.
 */
interface EventOnlyCommand extends RequestCommand
{
    public RequestId $requestId { get; }
}
