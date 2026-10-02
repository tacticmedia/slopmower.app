<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

final class ApiRequest
{
    /** The pattern of the `api` firewall. */
    private const string PATH_PREFIX = '/api/v1';

    public static function matches(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), self::PATH_PREFIX);
    }
}
