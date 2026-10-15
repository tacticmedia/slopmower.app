<?php

declare(strict_types=1);

namespace App\Http;

use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `before_send` callback of Sentry (D67): drops the event of an exception whose response is below 500. Sentry
 * captures at `kernel.exception` priority 128, before any listener answers, so {@see ApiExceptionListener::status()}
 * gives the status.
 */
final class SentryClientErrorFilter
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $request = $this->requestStack->getCurrentRequest();
        $exception = $hint?->exception;

        // Without a current request, a Messenger worker or a console command failed, and no response follows.
        return null !== $request && null !== $exception && ApiExceptionListener::status($exception, $request) < Response::HTTP_INTERNAL_SERVER_ERROR ? null : $event;
    }
}
