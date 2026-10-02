<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\RequestCommands;
use App\Domain\Actor\Actor;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\Reply;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReviewSeparationTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    #[TestDox("Review A's requests and updates never appear in review B")]
    public function testRequestsAndUpdatesStayInTheirReview(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $client = static::createClient();
        $user = self::signIn($client);
        $repositoryId = self::connectAndClone($client);
        $reviewA = (string) self::startReviewThroughApi($client, $repositoryId)['id'];
        $reviewB = (string) self::startReviewThroughApi($client, $repositoryId)['id'];
        $requestA = (string) self::openRequest($client, $reviewA)['id'];
        $requestB = (string) self::openRequest($client, $reviewB)['id'];
        self::asAgent($user, static function (RequestCommands $commands, Actor $agent) use ($requestA): void {
            $commands->claim(new Claim(RequestId::fromString($requestA)), $agent);
            $commands->reply(new Reply(RequestId::fromString($requestA), 'Should the tax lines use `Money` too?', true), $agent);
        });

        $responses = [];

        foreach ([$reviewA, $reviewB] as $reviewId) {
            $responses[$reviewId] = [
                'list' => self::assertApiResponse(self::apiRequest($client, 'GET', "/api/v1/reviews/{$reviewId}/requests"), 200, 'lists.schema.json#/$defs/RequestPage'),
                'waiting' => self::assertApiResponse(self::apiRequest($client, 'GET', "/api/v1/reviews/{$reviewId}/updates"), 200, 'updates-page.schema.json'),
                'events' => self::assertApiResponse(self::apiRequest($client, 'GET', "/api/v1/reviews/{$reviewId}/updates?cursor=0&includeOwn=true"), 200, 'updates-page.schema.json'),
            ];
        }

        self::assertSame([[$requestA], [$requestA], 1, [$requestA], ['review.started']], self::summary($responses[$reviewA]));
        self::assertSame([[$requestB], [], 0, [$requestB], ['review.started']], self::summary($responses[$reviewB]));
        self::assertStringNotContainsString($requestA, (string) json_encode($responses[$reviewB]));
        self::assertStringNotContainsString($reviewA, (string) json_encode($responses[$reviewB]));
    }

    /**
     * @param array<string, array<string, mixed>> $responses
     *
     * @return array{mixed, mixed, mixed, mixed, mixed} Listed IDs, waiting IDs, awaitingTotal, IDs with events, review event types
     */
    private static function summary(array $responses): array
    {
        return [
            array_column($responses['list']['items'], 'id'),
            array_column(array_column($responses['waiting']['requests'], 'request'), 'id'),
            $responses['waiting']['awaitingTotal'],
            array_column(array_column($responses['events']['requests'], 'request'), 'id'),
            array_column($responses['events']['reviewEvents'], 'type'),
        ];
    }
}
