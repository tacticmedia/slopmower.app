<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

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

final class BlameTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private const string ADA = 'Ada Lovelace <ada@example.invalid>';

    private const string GRACE = 'Grace Hopper <grace@example.invalid>';

    private KernelBrowser $client;

    private GitRepositoryBuilder $source;

    /** @var array{string, string, string} */
    private array $commits;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        $this->client = static::createClient();
        self::signIn($this->client);

        $first = $this->source->commit(['src/Old.php' => "The totals start here\nEach line adds the net amount\nThe tax rate comes from the table\nRounding happens per line\nThe total is the sum of lines\nThe result is in cents\n"], 'Add totals', self::ADA, '2026-01-02T03:04:05Z');
        // The rename drops line 3, so the lines 1 to 3 of the first commit come from two groups.
        $this->source->git('rm', '--quiet', 'src/Old.php');
        $second = $this->source->commit(['src/Totals.php' => "The totals start here\nEach line adds the net amount\nRounding happens per line\nThe TOTAL is the sum of lines\nThe result is in cents\n"], 'Rename and drop the tax line', self::GRACE, '2026-03-04T05:06:07Z');
        $third = $this->source->commit(['src/Totals.php' => "The totals start here\nEach line adds the net amount\nRounding happens per line\nThe TOTAL is the sum of lines\nThe result is in whole cents\n"], 'Say whole cents', self::ADA, '2026-05-06T07:08:09Z');
        $this->commits = [$first, $second, $third];
    }

    #[TestDox('The blame names the commit of each line across a rename, with adjacent lines of one commit in one range')]
    public function testRangesAndCommits(): void
    {
        [$first, $second, $third] = $this->commits;
        $review = self::startReviewThroughApi($this->client, self::connectAndClone($this->client));

        $blame = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$review['id']}/blame?path=src/Totals.php"), 200, 'blame.schema.json');

        self::assertSame([[1, 3, $first], [4, 4, $second], [5, 5, $third]], array_map(static fn (array $range): array => [$range['startLine'], $range['endLine'], $range['commitSha']], $blame['ranges']));
        self::assertSame([
            ['sha' => $first, 'authorName' => 'Ada Lovelace', 'authoredAt' => '2026-01-02T03:04:05Z', 'summary' => 'Add totals'],
            ['sha' => $second, 'authorName' => 'Grace Hopper', 'authoredAt' => '2026-03-04T05:06:07Z', 'summary' => 'Rename and drop the tax line'],
            ['sha' => $third, 'authorName' => 'Ada Lovelace', 'authoredAt' => '2026-05-06T07:08:09Z', 'summary' => 'Say whole cents'],
        ], $blame['commits']);
        self::assertSame([$review['commitSha'], $this->source->revParse('main:src/Totals.php'), false], [$blame['commitSha'], $blame['blobSha'], $blame['ignoreRevsFile']]);
    }

    #[TestDox('The commits that .git-blame-ignore-revs names are skipped, past comments and unknown commits')]
    public function testIgnoreRevsFile(): void
    {
        [$first, $second, $third] = $this->commits;
        $this->source->commit(['.git-blame-ignore-revs' => "# Wording only\n".str_repeat('0', 40)."\n{$third} # whole cents\n"], 'Ignore the wording');
        $review = self::startReviewThroughApi($this->client, self::connectAndClone($this->client));

        $blame = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$review['id']}/blame?path=src/Totals.php"), 200, 'blame.schema.json');

        self::assertTrue($blame['ignoreRevsFile']);
        self::assertSame([[1, 3, $first], [4, 4, $second], [5, 5, $first]], array_map(static fn (array $range): array => [$range['startLine'], $range['endLine'], $range['commitSha']], $blame['ranges']));
        self::assertSame([$first, $second], array_column($blame['commits'], 'sha'));
    }

    #[TestDox('A missing path gives 404, and a directory and a binary file give 422')]
    public function testRefusals(): void
    {
        $this->source->commit(['logo.png' => "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR"]);
        $review = self::startReviewThroughApi($this->client, self::connectAndClone($this->client));
        $blame = "/api/v1/reviews/{$review['id']}/blame?path=";

        self::assertApiError(self::apiRequest($this->client, 'GET', "{$blame}src/Missing.php"), 404, 'not_found');
        foreach (['src', 'logo.png'] as $path) {
            $error = self::assertApiError(self::apiRequest($this->client, 'GET', $blame.$path), 422, 'invalid_input');
            self::assertSame(['path'], array_column($error['details']['violations'] ?? [], 'field'), $path);
        }
    }

    #[TestDox('The blame answers If-None-Match with 304')]
    public function testIfNoneMatch(): void
    {
        $review = self::startReviewThroughApi($this->client, self::connectAndClone($this->client));
        $uri = "/api/v1/reviews/{$review['id']}/blame?path=src/Totals.php";
        $etag = "W/\"v1-{$review['commitSha']}-{$this->source->revParse('main:src/Totals.php')}\"";

        $first = self::apiRequest($this->client, 'GET', $uri);
        self::assertApiResponse($first, 200, 'blame.schema.json');
        $notModified = self::apiRequest($this->client, 'GET', $uri, server: ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertSame(304, $notModified->getStatusCode());
        self::assertSame('', (string) $notModified->getContent());
        foreach ([$first, $notModified] as $response) {
            self::assertSame($etag, $response->headers->get('ETag'));
            self::assertSame('no-cache, private', $response->headers->get('Cache-Control'));
        }
    }
}
