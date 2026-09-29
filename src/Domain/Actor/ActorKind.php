<?php

declare(strict_types=1);

namespace App\Domain\Actor;

enum ActorKind: string
{
    case User = 'user';
    case Agent = 'agent';
    case System = 'system';
}
