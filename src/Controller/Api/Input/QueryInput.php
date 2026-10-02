<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

/**
 * A #[MapQueryString] DTO. A value of the wrong type in it gives 400, in a body 422: {@see \App\Http\ApiExceptionListener}.
 */
interface QueryInput
{
}
