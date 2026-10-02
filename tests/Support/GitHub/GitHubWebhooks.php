<?php

declare(strict_types=1);

namespace App\Tests\Support\GitHub;

use App\Tenancy\TenantContext;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Signed deliveries of the payloads in tests/Fixtures/github/webhooks, for WebTestCase tests.
 */
trait GitHubWebhooks
{
    /** GITHUB_APP_WEBHOOK_SECRET in .env.test */
    private const string WEBHOOK_SECRET = 'fake-test-webhook-secret';

    /**
     * @param string               $fixture `<X-GitHub-Event>` or `<X-GitHub-Event>.<action>`
     * @param array<string, mixed> $changes Merged into the payload with array_replace_recursive()
     */
    private static function deliverFixture(KernelBrowser $client, string $fixture, array $changes = [], ?string $deliveryId = null): Response
    {
        return self::deliver($client, explode('.', $fixture)[0], self::webhookBody($fixture, $changes), $deliveryId);
    }

    /**
     * Without a tenant context, as at the start of a worker request.
     *
     * @param array<string, string> $server Replaces the server parameters of a signed GitHub delivery
     */
    private static function deliver(KernelBrowser $client, string $event, string $body, ?string $deliveryId = null, array $server = []): Response
    {
        $client->getContainer()->get(TenantContext::class)->reset();
        $client->request('POST', '/webhook/github', server: [...self::webhookServer($event, $body, $deliveryId), ...$server], content: $body);

        return $client->getResponse();
    }

    /**
     * @return array<string, string>
     */
    private static function webhookServer(string $event, string $body, ?string $deliveryId = null): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_GITHUB_DELIVERY' => $deliveryId ?? Uuid::v4()->toRfc4122(),
            'HTTP_X_HUB_SIGNATURE_256' => self::webhookSignature($body),
        ];
    }

    private static function webhookSignature(string $body, string $secret = self::WEBHOOK_SECRET): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private static function webhookBody(string $fixture, array $changes = []): string
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) file_get_contents(__DIR__."/../../Fixtures/github/webhooks/{$fixture}.json"), true, flags: \JSON_THROW_ON_ERROR);

        return json_encode(array_replace_recursive($payload, $changes), \JSON_THROW_ON_ERROR);
    }
}
