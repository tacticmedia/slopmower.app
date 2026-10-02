<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use App\Git\PinRefs;
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
 * Q8. The agent claimed two requests of a review and linked the review commit to both.
 */
final class UnlinkCommitTest extends WebTestCase
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

    private string $reviewCommit;

    private KernelBrowser $client;

    private User $user;

    private string $repositoryId;

    private string $secret;

    private string $first;

    private string $second;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->reviewCommit = $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->repositoryId = self::connectAndClone($this->client);
        $reviewId = (string) self::startReviewThroughApi($this->client, $this->repositoryId)['id'];
        $this->first = (string) self::openRequest($this->client, $reviewId)['id'];
        $this->second = (string) self::openRequest($this->client, $reviewId)['id'];
        [, $this->secret] = self::storeToken($this->user);
        self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$this->first}/claim");
        self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$this->second}/claim");
        self::agentRequest($this->client, $this->secret, 'POST', '/api/v1/commit-links', ['commitSha' => $this->reviewCommit, 'requestIds' => [$this->first, $this->second]], schema: 'link-commits-result.schema.json');
    }

    #[TestDox('Unlinking removes the link and the unused pin')]
    public function testUnlinkRemovesLinkAndUnusedPin(): void
    {
        $first = self::agentRequest($this->client, $this->secret, 'DELETE', "/api/v1/requests/{$this->first}/commits/{$this->reviewCommit}");
        $pinsWhileUsed = self::pins($this->user, $this->repositoryId);
        self::agentRequest($this->client, $this->secret, 'DELETE', "/api/v1/requests/{$this->second}/commits/{$this->reviewCommit}");

        self::assertSame(0, $first['request']['commitCount']);
        self::assertSame([['commit.unlinked', $this->reviewCommit, [$this->first]]], array_map(static fn (array $event): array => [$event['type'], $event['commitSha'], $event['requestIds']], $first['events']));
        self::assertNotContains('unlink_commit', $first['allowedActions']);
        self::assertSame($this->reviewCommit, $pinsWhileUsed[PinRefs::COMMITS.$this->reviewCommit] ?? null, 'The second request still uses the pin.');
        self::assertArrayNotHasKey(PinRefs::COMMITS.$this->reviewCommit, self::pins($this->user, $this->repositoryId));
        self::assertSame([], self::readRequest($this->client, $this->second)['commits']);
    }

    #[TestDox('Unlinking a commit that is not linked gives invalid_input')]
    public function testUnlinkingUnlinkedCommitGivesInvalidInput(): void
    {
        $notLinked = str_repeat('ab', 20);

        $error = self::assertApiError(self::bearerRequest($this->client, $this->secret, 'DELETE', "/api/v1/requests/{$this->first}/commits/{$notLinked}"), 422, 'invalid_input');

        self::assertSame('commitSha', $error['details']['violations'][0]['field'] ?? null);
    }
}
