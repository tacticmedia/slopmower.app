<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Security\CurrentActor;
use App\Tests\Support\Api\AgentApiFixtures;
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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The actor-kind rule of docs/specs/README.md section 3 for the agent endpoints.
 */
final class AgentActorKindTest extends WebTestCase
{
    use AgentApiFixtures;
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private KernelBrowser $client;

    private string $secret;

    private string $requestId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        $user = self::signIn($this->client);
        $this->requestId = (string) self::openRequest($this->client, self::startCodeReview($this->client))['id'];
        [, $this->secret] = self::storeToken($user);
    }

    #[TestDox('Claim with a session gives 403 forbidden')]
    public function testClaimWithSessionGivesForbidden(): void
    {
        $bearerOnly = [
            ['POST', "/api/v1/requests/{$this->requestId}/claim", null],
            ['POST', '/api/v1/commit-links', ['commitSha' => str_repeat('ab', 20), 'requestIds' => [$this->requestId]]],
            ['DELETE', "/api/v1/requests/{$this->requestId}/commits/".str_repeat('ab', 20), null],
            ['POST', "/api/v1/requests/{$this->requestId}/ready", ['note' => 'Done.']],
        ];

        foreach ($bearerOnly as [$method, $path, $body]) {
            $error = self::assertApiError(self::apiRequest($this->client, $method, $path, $body), 403, 'forbidden');

            self::assertSame(CurrentActor::BEARER_ONLY, $error['message'], "{$method} {$path}");
        }

        $request = self::readRequest($this->client, $this->requestId);
        self::assertSame(['open', null], [$request['status'], $request['claimedBy']]);
    }

    #[TestDox('Comments, release, and wont-do accept a bearer token')]
    public function testCommentsReleaseAndWontDoAcceptBearerToken(): void
    {
        $comment = self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$this->requestId}/comments", ['body' => 'Should the tax lines use `Money` too?', 'question' => true], 201);
        self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$this->requestId}/claim");
        $release = self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$this->requestId}/release", ['note' => 'Waiting for an answer.']);
        $wontDo = self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$this->requestId}/wont-do", ['reason' => self::WONT_DO_REASON]);

        self::assertSame(['request.commented', 'agent', true], [$comment['events'][0]['type'], $comment['events'][0]['actor']['kind'], $comment['events'][0]['question']]);
        self::assertSame(['request.released', 'open'], [$release['events'][0]['type'], $release['request']['status']]);
        self::assertSame(['request.wont_do', 'wont_do', 'user'], [$wontDo['events'][0]['type'], $wontDo['request']['status'], $wontDo['request']['awaiting']]);
    }
}
