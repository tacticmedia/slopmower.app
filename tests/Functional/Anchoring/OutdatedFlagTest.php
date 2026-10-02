<?php

declare(strict_types=1);

namespace App\Tests\Functional\Anchoring;

use App\Domain\Identity\ReviewId;
use App\Entity\User;
use App\Live\LiveTopics;
use App\Tests\Support\Api\AgentApiFixtures;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Contract\SchemaValidator;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Live\PublishedUpdates;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mercure\Update;

/**
 * The `outdated` flag. The request "edited" anchors lines 20 to 22 of the invoice totals, which the next default-branch
 * commit edits; the request "kept" anchors lines 40 to 42, which it keeps.
 */
final class OutdatedFlagTest extends WebTestCase
{
    use AgentApiFixtures;
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use McpAssertions;
    use PublishedUpdates;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private GitRepositoryBuilder $source;

    private KernelBrowser $client;

    private User $user;

    private string $repositoryId;

    private string $reviewId;

    private string $edited;

    private string $kept;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        $this->source->commit(self::codeFiles());
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->repositoryId = self::connectAndClone($this->client);
        $this->reviewId = (string) self::startReviewThroughApi($this->client, $this->repositoryId)['id'];
        $this->edited = $this->openRange(20, 22);
        $this->kept = $this->openRange(40, 42);
    }

    #[TestDox('A default-branch commit that edits the region sets outdated to true')]
    public function testEditOnDefaultBranchSetsOutdated(): void
    {
        $head = $this->commitEditAndFetch();

        $edited = self::readRequest($this->client, $this->edited);
        $review = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}"), 200, 'review.schema.json');

        self::assertSame([true, 'default_branch', $head, 'changed'], [$edited['outdated'], $edited['location']['target'], $edited['location']['targetSha'], $edited['location']['status']]);
        self::assertFalse(self::readRequest($this->client, $this->kept)['outdated']);
        self::assertSame(1, $review['counts']['outdated']);
    }

    #[TestDox('A new request gets its outdated flag without a fetch')]
    public function testNewRequestGetsItsFlagWithoutFetch(): void
    {
        $fetches = self::countFetches($this->client);

        $opened = self::openRequest($this->client, $this->reviewId, ['kind' => 'range', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 30, 'endLine' => 31]]);
        self::runQueuedMessages();

        self::assertNull($opened['outdated'], 'Null until computed.');
        self::assertFalse(self::readRequest($this->client, (string) $opened['id'])['outdated']);
        self::assertSame(0, $fetches());
    }

    #[TestDox('A projection rebuild keeps the outdated flag')]
    public function testProjectionRebuildKeepsTheFlag(): void
    {
        $this->commitEditAndFetch();

        $tester = new CommandTester(new Application(self::$kernel ?? self::fail('No kernel.'))->find('app:projections:rebuild'));
        $tester->execute(['--tenant' => $this->user->getTenantId()->toString()]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([true, false], [self::readRequest($this->client, $this->edited)['outdated'], self::readRequest($this->client, $this->kept)['outdated']]);
    }

    #[TestDox('The recompute publishes an outdated update with the request ID')]
    public function testRecomputePublishesOutdatedUpdate(): void
    {
        $this->commitEditAndFetch();

        $topic = self::getContainer()->get(LiveTopics::class)->review($this->user->getTenantId(), ReviewId::fromString($this->reviewId));
        $updates = array_values(array_filter(self::publishedUpdates(), static fn (Update $update): bool => 'outdated' === (self::updateData($update)['type'] ?? null)));

        self::assertCount(1, $updates);
        self::assertSame([[$topic], true, []], [$updates[0]->getTopics(), $updates[0]->isPrivate(), SchemaValidator::errors($updates[0]->getData(), 'live-update.schema.json')]);
        self::assertSame(['type' => 'outdated', 'reviewId' => $this->reviewId, 'requestIds' => [$this->edited]], self::updateData($updates[0]));
    }

    #[TestDox('The outdated filter finds the request')]
    public function testOutdatedFilterFindsTheRequest(): void
    {
        $this->commitEditAndFetch();
        [, $secret] = self::storeToken($this->user);
        $listed = fn (string $query): array => array_column(self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}/requests?{$query}"), 200, 'lists.schema.json#/$defs/RequestPage')['items'], 'id');
        $mcp = new McpTestClient($this->client, $secret);

        self::assertSame([[$this->edited], [$this->kept]], [$listed('outdated=true'), $listed('outdated=false')]);
        self::assertSame([$this->edited], array_column(self::assertToolResult($mcp, $mcp->callTool('list_requests', ['review' => $this->reviewId, 'outdated' => true]), 'list_requests')['items'], 'id'));
    }

    /**
     * Opens the request and runs its recompute, so its flag is false.
     */
    private function openRange(int $startLine, int $endLine): string
    {
        $id = (string) self::openRequest($this->client, $this->reviewId, ['kind' => 'range', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => $startLine, 'endLine' => $endLine]])['id'];
        self::runQueuedMessages();

        return $id;
    }

    /**
     * Edits line 21 on main, then runs R5 and the queued jobs.
     *
     * @return string The new head of main
     */
    private function commitEditAndFetch(): string
    {
        $head = $this->source->commit([self::INVOICE_TOTALS => str_replace("    \$total += 21;\n", "    \$total += 2100;\n", self::codeFiles()[self::INVOICE_TOTALS])]);
        self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/repositories/{$this->repositoryId}/fetch"), 202, 'repository.schema.json');
        self::runQueuedMessages();

        return $head;
    }
}
