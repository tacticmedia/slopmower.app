<?php

declare(strict_types=1);

namespace App\Domain\ApiToken;

enum ApiTokenState: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
