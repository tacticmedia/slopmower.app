<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Error\ErrorCode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * The 401 of docs/specs/README.md sections 3 and 4 for an unauthenticated call under /api/v1.
 */
final class ApiEntryPoint implements AuthenticationEntryPointInterface
{
    use TargetPathTrait;

    public const string WWW_AUTHENTICATE = 'Bearer realm="holistic-review"';

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        // The stateful firewall saves a target path before start(). Removing it leaves the session empty, so no row and no cookie.
        if ($request->hasSession()) {
            $this->removeTargetPath($request->getSession(), 'api');
        }

        return new JsonResponse(
            ['error' => ['code' => ErrorCode::Unauthorized->value, 'message' => 'Authentication is required.', 'details' => null]],
            Response::HTTP_UNAUTHORIZED,
            ['Content-Type' => 'application/json; charset=utf-8', 'WWW-Authenticate' => self::WWW_AUTHENTICATE],
        );
    }
}
