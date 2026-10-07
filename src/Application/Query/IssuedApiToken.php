<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\ApiToken\ApiTokenSecret;
use App\Entity\ApiToken;

/**
 * api-token.schema.json#/$defs/ApiTokenWithSecret: the only response that holds the secret.
 */
final readonly class IssuedApiToken
{
    public function __construct(
        public ApiToken $token,
        #[\SensitiveParameter] public ApiTokenSecret $secret,
    ) {
    }
}
