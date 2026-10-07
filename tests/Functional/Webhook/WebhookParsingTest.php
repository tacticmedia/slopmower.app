<?php

declare(strict_types=1);

namespace App\Tests\Functional\Webhook;

use App\Tests\Support\GitHub\GitHubWebhooks;
use App\Tests\Support\Messenger\QueuedMessages;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage;

final class WebhookParsingTest extends WebTestCase
{
    use GitHubWebhooks;
    use QueuedMessages;

    #[TestDox('A request without the GitHub headers gives 406')]
    public function testRequestWithoutGitHubHeadersGives406(): void
    {
        $client = static::createClient();
        $body = self::webhookBody('ping');

        foreach (['HTTP_X_GITHUB_EVENT', 'HTTP_X_GITHUB_DELIVERY'] as $header) {
            $server = self::webhookServer('ping', $body);
            unset($server[$header]);
            $client->request('POST', '/webhook/github', server: $server, content: $body);

            self::assertSame(406, $client->getResponse()->getStatusCode(), $header);
            self::assertSame([], self::asyncTransport()->getSent());
        }
    }

    #[TestDox('A signed delivery that is not an application/json POST gives 406')]
    public function testOtherContentTypeOrMethodGives406(): void
    {
        $client = static::createClient();
        $body = self::webhookBody('ping');

        self::assertSame(406, self::deliver($client, 'ping', $body, server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'])->getStatusCode());
        $client->request('PUT', '/webhook/github', server: self::webhookServer('ping', $body), content: $body);
        self::assertSame(406, $client->getResponse()->getStatusCode());
    }

    #[TestDox('A body that is not a JSON object gives 406, also with a valid signature')]
    public function testBodyOtherThanJsonObjectGives406(): void
    {
        $client = static::createClient();

        foreach (['{"zen": ', '"a JSON string"', '[{"zen": "Design for failure."}]', ' []'] as $body) {
            self::assertSame(406, self::deliver($client, 'ping', $body)->getStatusCode(), $body);
            self::assertSame([], self::asyncTransport()->getSent());
        }
    }

    #[TestDox('An event that the server does not handle gives 202 as github.ignored')]
    public function testUnhandledEventIsIgnored(): void
    {
        $client = static::createClient();

        $response = self::deliver($client, 'issues', '{"action": "opened"}');

        self::assertSame(202, $response->getStatusCode());
        $message = self::asyncTransport()->getSent()[0]->getMessage();
        self::assertInstanceOf(ConsumeRemoteEventMessage::class, $message);
        self::assertSame('github.ignored', $message->getEvent()->getName());
    }
}
