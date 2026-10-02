<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\RequestCommands;
use App\Domain\Actor\Actor;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\ReviewId;
use App\Domain\Request\Command\Open;
use App\Entity\User;
use App\Tenancy\TenantContext;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConcurrentReviewsTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private KernelBrowser $client;

    private User $user;

    private GitRepositoryBuilder $source;

    private string $repositoryId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->repositoryId = self::connectAndClone($this->client);
    }

    #[TestDox('Two open reviews of one repository coexist, each with its own pin')]
    public function testTwoOpenReviewsCoexist(): void
    {
        $first = self::startReviewThroughApi($this->client, $this->repositoryId);
        $second = self::startReviewThroughApi($this->client, $this->repositoryId);

        $page = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/repositories/{$this->repositoryId}/reviews"), 200, 'lists.schema.json#/$defs/ReviewPage');

        $head = $this->source->revParse('main');
        self::assertNotSame($first['id'], $second['id']);
        self::assertSame([$second['id'], $first['id']], array_column($page['items'], 'id'));
        self::assertSame([$head, $head], array_column($page['items'], 'commitSha'));
        self::assertSame(["refs/holistic-review/reviews/{$first['id']}" => $head, "refs/holistic-review/reviews/{$second['id']}" => $head], self::pins($this->user, $this->repositoryId));
        self::assertIsArray($page['repository']);
        self::assertSame([$this->repositoryId, 2], [$page['repository']['id'], $page['repository']['openReviewCount']]);
    }

    #[TestDox('A request counts only in its own review')]
    public function testRequestCountsOnlyInItsReview(): void
    {
        $withRequest = self::startReviewThroughApi($this->client, $this->repositoryId);
        $other = self::startReviewThroughApi($this->client, $this->repositoryId);
        $this->openRequest((string) $withRequest['id'], (string) $withRequest['commitSha']);

        $counted = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$withRequest['id']}"), 200, 'review.schema.json');
        $empty = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$other['id']}"), 200, 'review.schema.json');

        self::assertSame([
            'total' => 1,
            'byStatus' => ['open' => 1, 'in_progress' => 0, 'ready_for_review' => 0, 'verified' => 0, 'reopened' => 0, 'wont_do' => 0],
            'byAwaiting' => ['agent' => 1, 'user' => 0, 'none' => 0],
            'outdated' => 0,
        ], $counted['counts']);
        self::assertIsArray($empty['counts']);
        self::assertSame(0, $empty['counts']['total']);
    }

    #[TestDox('The list pages by createdAt descending, then by ID descending')]
    public function testListPagesWithCursor(): void
    {
        $ids = array_map(fn (): mixed => self::startReviewThroughApi($this->client, $this->repositoryId)['id'], range(1, 3));

        $first = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/repositories/{$this->repositoryId}/reviews?limit=2"), 200, 'lists.schema.json#/$defs/ReviewPage');
        $second = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/repositories/{$this->repositoryId}/reviews?limit=2&cursor={$first['nextCursor']}"), 200, 'lists.schema.json#/$defs/ReviewPage');

        self::assertSame([$ids[2], $ids[1]], array_column($first['items'], 'id'));
        self::assertSame([$ids[0]], array_column($second['items'], 'id'));
        self::assertNull($second['nextCursor']);
    }

    private function openRequest(string $reviewId, string $commitSha): void
    {
        self::getContainer()->get(TenantContext::class)->set($this->user->getTenantId());
        $anchor = Anchor::of(AnchorKind::File, RepoPath::fromString('README.md'), CommitSha::fromString($commitSha), BlobSha::fromString($this->source->revParse('main:README.md')), null, null, null);
        self::getContainer()->get(RequestCommands::class)->open(ReviewId::fromString($reviewId), new Open($anchor, 'Explain the greeting.'), Actor::user($this->user->getId()));
    }
}
