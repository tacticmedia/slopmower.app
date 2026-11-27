<?php

declare(strict_types=1);

namespace App\Domain\Error;

final class Forbidden extends DomainError
{
    public function code(): ErrorCode
    {
        return ErrorCode::Forbidden;
    }

    public function details(): null
    {
        return null;
    }
}
