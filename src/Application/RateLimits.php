<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Error\RateLimited;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\TenantId;
use App\Entity\User;
use App\Security\AgentUser;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The limiters of docs/specs/README.md section "Rate limits" (config/packages/rate_limiter.yaml). Each method consumes
 * one token and throws when a limiter refuses it.
 */
final class RateLimits
{
    public function __construct(
        #[Target('api_token')]
        private readonly RateLimiterFactoryInterface $apiTokenLimiter,
        #[Target('api_user')]
        private readonly RateLimiterFactoryInterface $apiUserLimiter,
        #[Target('mcp_http')]
        private readonly RateLimiterFactoryInterface $mcpHttpLimiter,
        #[Target('sync_fetch')]
        private readonly RateLimiterFactoryInterface $syncFetchLimiter,
        #[Target('review_start')]
        private readonly RateLimiterFactoryInterface $reviewStartLimiter,
        private readonly Security $security,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * A REST call or an MCP tool call: `api_token` for a token, then `api_user`. Without a caller it does nothing.
     *
     * @throws RateLimited
     */
    public function consumeCall(): void
    {
        $caller = $this->security->getUser();

        if ($caller instanceof AgentUser) {
            $this->consume($this->apiTokenLimiter, $caller->tokenId->toString());
            $this->consume($this->apiUserLimiter, $caller->userId->toString());
        } elseif ($caller instanceof User) {
            $this->consume($this->apiUserLimiter, $caller->getId()->toString());
        }
    }

    /**
     * An HTTP request to /_mcp. Without a token it does nothing.
     *
     * @throws RateLimited
     */
    public function consumeMcpRequest(): void
    {
        $caller = $this->security->getUser();

        if ($caller instanceof AgentUser) {
            $this->consume($this->mcpHttpLimiter, $caller->tokenId->toString());
        }
    }

    /**
     * @throws RateLimited
     */
    public function consumeSyncFetch(RepositoryId $repositoryId): void
    {
        $this->consume($this->syncFetchLimiter, $repositoryId->toString());
    }

    /**
     * @throws RateLimited
     */
    public function consumeReviewStart(TenantId $tenantId): void
    {
        $this->consume($this->reviewStartLimiter, $tenantId->toString());
    }

    /**
     * Retry-After is at least 1 s, because the limiter rounds the retry time down to the second.
     *
     * @throws RateLimited
     */
    private function consume(RateLimiterFactoryInterface $limiter, string $key): void
    {
        $rateLimit = $limiter->create($key)->consume();

        if (!$rateLimit->isAccepted()) {
            $retryAfterSeconds = max(1, $rateLimit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp());

            throw new RateLimited($retryAfterSeconds, $rateLimit->getLimit(), $rateLimit->getRemainingTokens());
        }
    }
}
