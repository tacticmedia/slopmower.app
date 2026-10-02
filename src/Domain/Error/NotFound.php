<?php

declare(strict_types=1);

namespace App\Domain\Error;

final class NotFound extends DomainError
{
    public function __construct(string $message = 'The resource does not exist.')
    {
        parent::__construct($message);
    }

    public function code(): ErrorCode
    {
        return ErrorCode::NotFound;
    }

    public function details(): null
    {
        return null;
    }
}
