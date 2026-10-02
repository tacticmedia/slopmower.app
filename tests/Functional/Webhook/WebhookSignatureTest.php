<?php

declare(strict_types=1);

namespace App\Tests\Functional\Webhook;

use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\GitHub\GitHubWebhooks;
use App\Tests\Support\Messenger\QueuedMessages;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage;

final class WebhookSignatureTest extends WebTestCase
{
    use EnvironmentVariables;
    use GitHubWebhooks;
    use QueuedMessages;

    #[TestDox('A valid signature gives 202')]
    public function testValidSignatureGives202(): void
    {
        $client = static::createClient();
        $body = self::webhookBody('ping');

        $response = self::deliver($client, 'ping', $body, '72d3162e-cc78-11e3-81ab-4c9367dc0958');

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        $sent = self::asyncTransport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(ConsumeRemoteEventMessage::class, $message);
        self::assertSame(['github', 'github.ping', '72d3162e-cc78-11e3-81ab-4c9367dc0958'], [$message->getType(), $message->getEvent()->getName(), $message->getEvent()->getId()]);
        self::assertSame(json_decode($body, true), $message->getEvent()->getPayload());
    }

    #[TestDox('A missing or wrong signature gives 403')]
    public function testMissingOrWrongSignatureGives403(): void
    {
        $client = static::createClient();
        $body = self::webhookBody('ping');
        $server = self::webhookServer('ping', $body);
        unset($server['HTTP_X_HUB_SIGNATURE_256']);

        $client->request('POST', '/webhook/github', server: $server, content: $body);
        self::assertSame(403, $client->getResponse()->getStatusCode());

        self::assertSame(403, self::deliver($client, 'ping', $body, server: ['HTTP_X_HUB_SIGNATURE_256' => self::webhookSignature($body, 'another-secret')])->getStatusCode());
        self::assertSame(403, self::deliver($client, 'ping', $body, server: ['HTTP_X_HUB_SIGNATURE_256' => self::webhookSignature($body.' ')])->getStatusCode());
        self::assertSame(403, self::deliver($client, 'ping', $body, server: ['HTTP_X_HUB_SIGNATURE_256' => strtoupper(self::webhookSignature($body))])->getStatusCode());
    }

    #[TestDox('A request with only the SHA-1 header gives 403')]
    public function testSha1HeaderAloneGives403(): void
    {
        $client = static::createClient();
        $body = self::webhookBody('ping');
        $server = ['HTTP_X_HUB_SIGNATURE' => 'sha1='.hash_hmac('sha1', $body, self::WEBHOOK_SECRET)] + self::webhookServer('ping', $body);
        unset($server['HTTP_X_HUB_SIGNATURE_256']);

        $client->request('POST', '/webhook/github', server: $server, content: $body);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    #[TestDox('With an empty secret, every delivery gets 403')]
    public function testEmptySecretRefusesEveryDelivery(): void
    {
        $this->setEnvironmentVariable('GITHUB_APP_WEBHOOK_SECRET', '');
        $client = static::createClient();
        $body = self::webhookBody('ping');

        $response = self::deliver($client, 'ping', $body, server: ['HTTP_X_HUB_SIGNATURE_256' => self::webhookSignature($body, '')]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], self::asyncTransport()->getSent());
    }
}
