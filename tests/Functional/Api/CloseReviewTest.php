<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use App\Tests\Support\Api\ApiFixtures;
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
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class CloseReviewTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private KernelBrowser $client;

    private User $user;

    private string $repositoryId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource();
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->repositoryId = self::connectAndClone($this->client);
    }

    #[TestDox('Closing stores closedAt, closedBy, and the note')]
    public function testCloseStoresClosedAtClosedByAndNote(): void
    {
        $review = self::startReviewThroughApi($this->client, $this->repositoryId);
        self::mockTime('2026-09-28T16:30:00Z');

        $closed = self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$review['id']}/close", ['note' => 'Shipped in 2.4']), 200, 'review.schema.json');
        $read = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$review['id']}"), 200, 'review.schema.json');

        $expected = [
            'state' => 'closed',
            'closedAt' => '2026-09-28T16:30:00Z',
            'closedBy' => ['kind' => 'user', 'userId' => $this->user->getId()->toString(), 'agentName' => null, 'name' => 'jan', 'avatarUrl' => null],
            'closeNote' => 'Shipped in 2.4',
        ];
        self::assertSame($expected, array_intersect_key($closed, $expected));
        self::assertSame($expected, array_intersect_key($read, $expected));
    }

    #[TestDox('The list hides closed reviews by default and shows them with state closed or all')]
    public function testListFiltersByState(): void
    {
        $closed = self::startReviewThroughApi($this->client, $this->repositoryId);
        $open = self::startReviewThroughApi($this->client, $this->repositoryId);
        self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$closed['id']}/close"), 200, 'review.schema.json');

        $ids = [];

        foreach (['', '?state=open', '?state=closed', '?state=all'] as $query) {
            $page = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/repositories/{$this->repositoryId}/reviews{$query}"), 200, 'lists.schema.json#/$defs/ReviewPage');
            $ids[$query] = array_column($page['items'], 'id');
        }

        self::assertSame(['' => [$open['id']], '?state=open' => [$open['id']], '?state=closed' => [$closed['id']], '?state=all' => [$open['id'], $closed['id']]], $ids);
    }

    #[TestDox('Closing a closed review gives 409 review_closed')]
    public function testClosingClosedReviewGivesReviewClosed(): void
    {
        $review = self::startReviewThroughApi($this->client, $this->repositoryId);
        $closed = self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$review['id']}/close", ['note' => null]), 200, 'review.schema.json');

        $error = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$review['id']}/close", ['note' => 'Again']), 409, 'review_closed');

        self::assertSame(['reviewId' => $review['id'], 'closedAt' => $closed['closedAt']], $error['details']);
    }
}
