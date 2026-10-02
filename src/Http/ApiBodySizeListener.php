<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS, priority: self::PRIORITY)]
final class ApiBodySizeListener
{
    public const int MAX_BODY_BYTES = 1_048_576;

    /** After the 403 checks, #[IsGranted] at -10000, and before #[MapRequestPayload] at -10100. */
    private const int PRIORITY = -10050;

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        $request = $event->getRequest();

        if (!ApiRequest::matches($request)) {
            return;
        }

        if ((int) $request->headers->get('Content-Length') > self::MAX_BODY_BYTES || strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            throw new HttpException(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
    }
}
