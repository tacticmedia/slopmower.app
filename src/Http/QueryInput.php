<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A #[MapQueryString] DTO. A value of the wrong type in it gives 400, in a body 422: {@see ApiExceptionListener}.
 */
interface QueryInput
{
}
