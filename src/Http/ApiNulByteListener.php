<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Error\InvalidInput;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * PostgreSQL refuses U+0000 in `text` and `jsonb`, so a NUL byte in any query value or JSON body string is
 * invalid_input instead of a failed transaction.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS, priority: self::PRIORITY)]
final class ApiNulByteListener
{
    /** After {@see ApiBodySizeListener}, and before #[MapRequestPayload] at -10100. */
    private const int PRIORITY = -10060;

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        $request = $event->getRequest();

        if (!ApiRequest::matches($request)) {
            return;
        }

        self::refuseNulByte($request->query->all(), '');

        // A body that is not JSON is left to #[MapRequestPayload], which answers it.
        $body = json_decode($request->getContent(), true);

        if (\is_array($body)) {
            self::refuseNulByte($body, '');
        }
    }

    /**
     * @param array<mixed> $values
     *
     * @throws InvalidInput
     */
    private static function refuseNulByte(array $values, string $path): void
    {
        foreach ($values as $key => $value) {
            $field = '' === $path ? (string) $key : "{$path}.{$key}";

            if (\is_array($value)) {
                self::refuseNulByte($value, $field);
            } elseif (\is_string($value) && str_contains($value, "\0")) {
                throw InvalidInput::field($field, 'Must not hold a NUL byte.');
            }
        }
    }
}
