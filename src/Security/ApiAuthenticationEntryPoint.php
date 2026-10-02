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

final class ApiAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    use TargetPathTrait;

    private const string FIREWALL = 'api';

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        // The firewall stored a target path. Without it the session stays empty, so it is neither saved nor sent as a cookie.
        if ($request->hasSession()) {
            $this->removeTargetPath($request->getSession(), self::FIREWALL);
        }

        return new JsonResponse(
            ['error' => ['code' => ErrorCode::Unauthorized->value, 'message' => 'Authentication is required.', 'details' => null]],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer realm="holistic-review"'],
        );
    }
}
