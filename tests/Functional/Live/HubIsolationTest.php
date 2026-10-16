<?php

declare(strict_types=1);

namespace App\Tests\Functional\Live;

use App\Domain\Identity\ReviewId;
use App\Live\LivePublisher;
use App\Live\LiveTopics;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Chunk\ServerSentEvent;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The e2e hub on port 8081 checks the subscriber JWT of A3 against the private updates of LivePublisher.
 */
final class HubIsolationTest extends WebTestCase
{
    use ApiFixtures;
    use JsonApiAssertions;
    use ResetDatabase;

    private const float WAIT_SECONDS = 2.0;

    #[TestDox("A's JWT receives A's update and not B's within 2 seconds")]
    public function testSubscriberReceivesOnlyItsTenantsUpdates(): void
    {
        $client = static::createClient();
        $tenantA = self::signIn($client)->getTenantId();
        $tenantB = TenantFixtures::of(self::getContainer())->withLogin('other')->create()->getTenantId();
        $response = self::apiRequest($client, 'POST', '/api/v1/mercure/authorize');
        $hubUrl = self::assertApiResponse($response, 200, 'lists.schema.json#/$defs/MercureAuthorization')['hubUrl'];
        \assert(\is_string($hubUrl));
        [$cookie] = $response->headers->getCookies();
        $topics = self::getContainer()->get(LiveTopics::class);
        $publisher = self::getContainer()->get(LivePublisher::class);
        $reviewA = ReviewId::generate();
        $reviewB = ReviewId::generate();
        $subscribed = [$topics->repositories($tenantB), $topics->review($tenantB, $reviewB), $topics->review($tenantA, $reviewA)];
        $sse = new EventSourceHttpClient(HttpClient::create());
        $source = $sse->connect($hubUrl.'?topic='.implode('&topic=', array_map(rawurlencode(...), $subscribed)), ['headers' => ['Cookie' => "{$cookie->getName()}={$cookie->getValue()}"]]);
        // The hub sends the headers after it registered the subscriber.
        self::assertSame(200, $source->getStatusCode());

        $publisher->publish($topics->repositories($tenantB), ['type' => 'candidates_changed']);
        $publisher->publish($topics->review($tenantB, $reviewB), ['type' => 'index', 'reviewId' => $reviewB->toString()]);
        $publisher->publish($topics->review($tenantA, $reviewA), ['type' => 'index', 'reviewId' => $reviewA->toString()]);

        $received = [];
        $deadline = microtime(true) + self::WAIT_SECONDS;

        // The stream ends with the first timeout chunk, and a timeout chunk that isTimeout() does not check throws.
        foreach ($sse->stream($source, self::WAIT_SECONDS) as $chunk) {
            if (!$chunk->isTimeout() && $chunk instanceof ServerSentEvent && '' !== $chunk->getData()) {
                $received[] = json_decode($chunk->getData(), true, flags: \JSON_THROW_ON_ERROR);
            }

            // The hub delivers in publishing order, so B's updates would arrive before A's.
            if ([] !== $received || microtime(true) > $deadline) {
                break;
            }
        }

        $source->cancel();
        self::assertSame([['type' => 'index', 'reviewId' => $reviewA->toString()]], $received);
    }
}
