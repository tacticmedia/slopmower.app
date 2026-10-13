<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\RateLimits;
use App\Domain\Error\RateLimited;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The limiters per call of docs/specs/README.md section "Rate limits" for /api/v1, and `mcp_http` for /_mcp. The MCP
 * tool calls consume `api_token` and `api_user` in {@see \App\Mcp\McpTools}. {@see ApiExceptionListener} answers a
 * {@see RateLimited}.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: self::PRIORITY)]
final class RateLimitListener
{
    /** After the firewall at 8 and the tenant context at 7, so the caller is known. */
    private const int PRIORITY = 6;

    /** The pattern of the `mcp` firewall. */
    private const string MCP_PATH_PREFIX = '/_mcp';

    public function __construct(private readonly RateLimits $rateLimits)
    {
    }

    /**
     * @throws RateLimited
     */
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest()) {
            return;
        }

        if (ApiRequest::matches($request)) {
            $this->rateLimits->consumeCall();
        } elseif (str_starts_with($request->getPathInfo(), self::MCP_PATH_PREFIX)) {
            $this->rateLimits->consumeMcpRequest();
        }
    }
}
