<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Security\SessionUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets the tenant context from the authenticated user. Priority 7 runs right after the firewall at 8.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final class TenantContextListener
{
    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if ($user instanceof SessionUser) {
            $this->tenantContext->set($user->tenantId());
        }
    }
}
