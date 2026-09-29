<?php

declare(strict_types=1);

namespace App\Domain\Error;

final class NotFound extends DomainError
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function resource(string $resourceName, \Stringable|string $identifier): self
    {
        return new self(\sprintf('%s %s was not found.', $resourceName, $identifier));
    }

    public function code(): ErrorCode
    {
        return ErrorCode::NotFound;
    }
}
