<?php

declare(strict_types=1);

namespace App\Webhook;

use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\HeaderRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * docs/specs/webhooks-and-live-updates.md section 1. A request that does not match, an unparsable body, and a body
 * that is not a JSON object get 406.
 */
final class GitHubRequestParser extends AbstractRequestParser
{
    private const array HANDLED_EVENTS = ['ping', 'push', 'installation', 'installation_repositories', 'repository', 'github_app_authorization'];

    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([
            new MethodRequestMatcher('POST'),
            new HeaderRequestMatcher(['X-GitHub-Event', 'X-GitHub-Delivery']),
            new class implements RequestMatcherInterface {
                public function matches(Request $request): bool
                {
                    return 'application/json' === strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
                }
            },
        ]);
    }

    /**
     * Anyone can compute an HMAC with an empty key, so an empty secret refuses every delivery.
     */
    protected function doParse(Request $request, #[\SensitiveParameter] string $secret): RemoteEvent
    {
        $signature = $request->headers->get('X-Hub-Signature-256');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if ('' === $secret || null === $signature || !hash_equals($expected, $signature)) {
            throw new RejectWebhookException(Response::HTTP_FORBIDDEN, 'Invalid signature.');
        }

        $event = (string) $request->headers->get('X-GitHub-Event');
        $name = \in_array($event, self::HANDLED_EVENTS, true) ? "github.{$event}" : 'github.ignored';

        $payload = $request->toArray();

        // toArray() also decodes a JSON list. Of the valid JSON bodies, only an object starts with "{".
        if (!str_starts_with(ltrim($request->getContent()), '{')) {
            throw new RejectWebhookException(Response::HTTP_NOT_ACCEPTABLE, 'Request body is not a JSON object.');
        }

        return new RemoteEvent($name, (string) $request->headers->get('X-GitHub-Delivery'), $payload);
    }
}
