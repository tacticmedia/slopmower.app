<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Domain\Identity\TenantId;
use App\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * A received envelope runs under the tenant of its stamp. The entity manager is cleared afterwards only when no tenant
 * context existed before, so a message that `sync://` handles inside a request keeps the entities of that request.
 */
final class TenantContextMiddleware implements MiddlewareInterface
{
    /**
     * @param list<class-string> $tenantlessMessageClasses
     */
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(param: 'app.tenantless_messages')]
        private readonly array $tenantlessMessageClasses,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $previousTenantId = $this->tenantContext->current();

        if (null === $envelope->last(TenantStamp::class) && null !== $previousTenantId) {
            $envelope = $envelope->with(new TenantStamp($previousTenantId));
        }

        if (null === $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $messageClass = $envelope->getMessage()::class;
        $tenantId = $envelope->last(TenantStamp::class)?->tenantId;

        if (null === $tenantId && !in_array($messageClass, $this->tenantlessMessageClasses, true)) {
            throw new UnrecoverableMessageHandlingException("The message {$messageClass} has no TenantStamp.");
        }

        $this->enter($tenantId);

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->enter($previousTenantId);

            if (null === $previousTenantId) {
                $this->entityManager->clear();
            }
        }
    }

    private function enter(?TenantId $tenantId): void
    {
        if (null === $tenantId) {
            $this->tenantContext->reset();
        } else {
            $this->tenantContext->set($tenantId);
        }
    }
}
