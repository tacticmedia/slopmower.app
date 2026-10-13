<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Server\Transport\StreamableHttpTransport;
use Symfony\AI\McpBundle\Controller\McpController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;

/**
 * One request per MCP session at a time: the SDK queues each response in the session and rewrites the whole session
 * without a lock, so two parallel requests of one session can lose or swap a response. No tool suspends, so the SDK
 * answers with JSON and touches the session only inside handle().
 */
#[AsDecorator('mcp.server.slopmower.controller')]
final class SessionLockedMcpController
{
    private const float LOCK_TIMEOUT_SECONDS = 30.0;
    private const float LOCK_POLL_SECONDS = 0.1;

    public function __construct(
        #[AutowireDecorated] private readonly McpController $inner,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
    ) {
    }

    public function handle(Request $request): Response
    {
        $sessionId = self::sessionId($request);

        // The SDK answers a missing or malformed session ID itself.
        if (null === $sessionId) {
            return $this->inner->handle($request);
        }

        // No TTL: PostgreSQL advisory locks do not expire and end with the database session.
        $lock = $this->lockFactory->createLock('mcp-session-'.$sessionId->toRfc4122(), ttl: null);
        $deadlineUnixSeconds = $this->nowUnixSeconds() + self::LOCK_TIMEOUT_SECONDS;

        // Polls, because Symfony Lock has no acquire timeout and the PostgreSQL store blocks without limit.
        while (!$lock->acquire()) {
            if ($this->nowUnixSeconds() >= $deadlineUnixSeconds) {
                throw new ServiceUnavailableHttpException(1, 'Another request of this MCP session is still running.');
            }

            $this->clock->sleep(self::LOCK_POLL_SECONDS);
        }

        try {
            return $this->inner->handle($request);
        } finally {
            $lock->release();
        }
    }

    /**
     * Parses as {@see StreamableHttpTransport} does, so every form of an ID that the SDK accepts takes the lock.
     */
    private static function sessionId(Request $request): ?Uuid
    {
        $header = $request->headers->get(StreamableHttpTransport::SESSION_HEADER);

        if (null === $header || '' === $header) {
            return null;
        }

        try {
            return Uuid::fromString($header);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function nowUnixSeconds(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
