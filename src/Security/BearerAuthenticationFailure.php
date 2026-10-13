<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Error\ErrorCode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * The 401 of docs/specs/README.md section 3. As the entry point it also answers a Bearer header that
 * HeaderAccessTokenExtractor rejects, for example `Bearer sm_%%%`, because then no authenticator runs.
 */
final class BearerAuthenticationFailure implements AuthenticationFailureHandlerInterface, AuthenticationEntryPointInterface
{
    private const string CHALLENGE = 'Bearer realm="slopmower"';

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return self::unauthorized(tokenSent: true);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::unauthorized(BearerApiRequestMatcher::hasBearerCredential($request));
    }

    private static function unauthorized(bool $tokenSent): JsonResponse
    {
        $message = $tokenSent ? 'The API token is malformed, unknown, revoked, or expired.' : 'Authentication is required.';

        return new JsonResponse(
            ['error' => ['code' => ErrorCode::Unauthorized->value, 'message' => $message, 'details' => null]],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => self::CHALLENGE.($tokenSent ? ', error="invalid_token"' : '')],
        );
    }
}
