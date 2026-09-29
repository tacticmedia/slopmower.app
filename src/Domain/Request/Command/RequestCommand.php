<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

/**
 * A command of architecture section 6 for {@see \App\Domain\Request\RequestDecider::decide()}.
 * Each command checks its own input at construction and throws InvalidInput.
 */
interface RequestCommand
{
}
