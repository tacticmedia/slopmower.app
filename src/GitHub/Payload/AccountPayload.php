<?php

declare(strict_types=1);

namespace App\GitHub\Payload;

/**
 * The `account` of a user or organization installation. An enterprise account has no login.
 */
final readonly class AccountPayload
{
    public function __construct(
        public int $id,
        public string $login,
    ) {
    }
}
