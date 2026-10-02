<?php

declare(strict_types=1);

namespace App\Live;

use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The topics of docs/specs/webhooks-and-live-updates.md section 3.
 */
final readonly class LiveTopics
{
    private string $base;

    public function __construct(#[Autowire(env: 'DEFAULT_URI')] string $defaultUri)
    {
        $this->base = rtrim($defaultUri, '/');
    }

    public function repositories(TenantId $tenantId): string
    {
        return "{$this->base}/tenants/{$tenantId}/repositories";
    }

    public function review(TenantId $tenantId, ReviewId $reviewId): string
    {
        return "{$this->base}/tenants/{$tenantId}/reviews/{$reviewId}";
    }

    /**
     * The `mercure.subscribe` claim: the repositories topic, and a URI template that matches every review of the tenant.
     *
     * @return list<string>
     */
    public function subscriberSelectors(TenantId $tenantId): array
    {
        return [$this->repositories($tenantId), "{$this->base}/tenants/{$tenantId}/reviews/{reviewId}"];
    }
}
