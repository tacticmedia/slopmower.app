<?php

declare(strict_types=1);

namespace App\Tenancy;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Reading the user also runs the authentication that a lazy firewall defers.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: self::PRIORITY)]
final class TenantContextListener
{
    /** Directly after the firewall at 8. */
    private const int PRIORITY = 7;

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

        if ($user instanceof TenantOwned) {
            $this->tenantContext->set($user->getTenantId());
        }
    }
}
