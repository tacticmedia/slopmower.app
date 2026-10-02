<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

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

final class ClosedReviewWritesTest extends WebTestCase
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

    private KernelBrowser $client;

    private string $reviewId;

    private string $requestId;

    private string $closedAt;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        self::signIn($this->client);
        $this->reviewId = self::startCodeReview($this->client);
        $this->requestId = (string) self::openRequest($this->client, $this->reviewId)['id'];
        $closed = self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/close"), 200, 'review.schema.json');
        $this->closedAt = (string) $closed['closedAt'];
    }

    #[TestDox('Every write on a closed review gives 409 review_closed')]
    public function testEveryWriteGivesReviewClosed(): void
    {
        $writes = [
            ["/api/v1/reviews/{$this->reviewId}/requests", ['anchor' => ['kind' => 'file', 'path' => 'README.md'], 'body' => 'Too late.']],
            ["/api/v1/requests/{$this->requestId}/comments", ['body' => 'Too late.']],
            ["/api/v1/requests/{$this->requestId}/release", null],
            ["/api/v1/requests/{$this->requestId}/wont-do", ['reason' => self::WONT_DO_REASON]],
            ["/api/v1/requests/{$this->requestId}/verify", null],
            ["/api/v1/requests/{$this->requestId}/reopen", ['note' => 'Too late.']],
        ];

        foreach ($writes as [$path, $body]) {
            $error = self::assertApiError(self::apiRequest($this->client, 'POST', $path, $body), 409, 'review_closed');

            self::assertSame(['reviewId' => $this->reviewId, 'closedAt' => $this->closedAt], $error['details'], $path);
        }
    }

    #[TestDox('Reads still work and allowedActions is empty')]
    public function testReadsWorkAndAllowedActionsIsEmpty(): void
    {
        $request = self::readRequest($this->client, $this->requestId);
        $page = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}/requests"), 200, 'lists.schema.json#/$defs/RequestPage');
        $updates = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}/updates"), 200, 'updates-page.schema.json');

        self::assertSame(['closed', []], [$request['reviewState'], $request['allowedActions']]);
        self::assertSame(['closed', [$this->requestId]], [$page['reviewState'], array_column($page['items'], 'id')]);
        self::assertSame(['closed', ['review.started', 'review.closed']], [$updates['reviewState'], array_column($updates['reviewEvents'], 'type')]);
    }
}
