<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\ApiRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/**
 * Selects the stateless firewall `api_bearer`, so a bearer token wins over a session cookie.
 */
final class BearerApiRequestMatcher implements RequestMatcherInterface
{
    public function matches(Request $request): bool
    {
        return ApiRequest::matches($request) && self::hasBearerCredential($request);
    }

    /**
     * The authentication scheme is case-insensitive (RFC 9110 section 11.1).
     */
    public static function hasBearerCredential(Request $request): bool
    {
        return 1 === preg_match('/^Bearer(\s|$)/i', (string) $request->headers->get('Authorization'));
    }
}
