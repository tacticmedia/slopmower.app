<?php

declare(strict_types=1);

namespace App\Domain\Request;

enum Awaiting: string
{
    case Agent = 'agent';
    case User = 'user';
    case None = 'none';
}
