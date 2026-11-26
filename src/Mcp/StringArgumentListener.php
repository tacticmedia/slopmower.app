<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\RegistryInterface;
use Mcp\Event\RequestEvent;
use Mcp\Schema\Request\CallToolRequest;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Casts the string form of an integer or boolean argument before the input check of the SDK, because a client sends an
 * input that its older tools/list lacks as a string (D146).
 */
#[AsEventListener]
final readonly class StringArgumentListener
{
    public function __construct(
        #[Autowire(service: 'mcp.server.slopmower.registry')] private RegistryInterface $registry,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$request instanceof CallToolRequest || !$this->registry->hasTool($request->name)) {
            return;
        }

        $properties = (array) ($this->registry->getTool($request->name)->tool->inputSchema['properties'] ?? []);
        $arguments = $request->arguments;

        foreach ($arguments as $name => $value) {
            $type = \is_array($properties[$name] ?? null) ? $properties[$name]['type'] ?? null : null;
            $types = array_values(array_diff((array) $type, ['null']));

            $arguments[$name] = match (true) {
                !\is_string($value) => $value,
                // A leading zero or a value outside the PHP integer range stays a string, which the input check refuses.
                ['integer'] === $types && 1 === preg_match('/^-?[0-9]+$/D', $value) => filter_var($value, \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE) ?? $value,
                ['boolean'] === $types && \in_array($value, ['true', 'false'], true) => 'true' === $value,
                default => $value,
            };
        }

        if ($arguments !== $request->arguments) {
            $event->setRequest(new CallToolRequest($request->name, $arguments)->withId($request->getId())->withMeta($request->getMeta()));
        }
    }
}
