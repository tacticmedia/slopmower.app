<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The JSON charset of docs/specs/README.md section 2.1, and `no-store` for every response without an ETag (section 6).
 * Runs before the session listener at -1000, which would otherwise rewrite Cache-Control.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class ApiResponseListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !ApiRequest::matches($event->getRequest())) {
            return;
        }

        $headers = $event->getResponse()->headers;

        if ('application/json' === $headers->get('Content-Type')) {
            $headers->set('Content-Type', 'application/json; charset=utf-8');
        }

        $headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        if (!$headers->has('ETag')) {
            $headers->set('Cache-Control', 'no-store');
        }
    }
}
