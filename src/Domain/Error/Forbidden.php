<?php

declare(strict_types=1);

namespace App\Domain\Error;

use App\Domain\Actor\ActorKind;

final class Forbidden extends DomainError
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function actorKind(ActorKind $actorKind, string $commandName): self
    {
        return new self(\sprintf('An actor of kind %s may not run %s.', $actorKind->value, $commandName));
    }

    public function code(): ErrorCode
    {
        return ErrorCode::Forbidden;
    }
}
