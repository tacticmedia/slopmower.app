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
use Symfony\Component\HttpFoundation\Response;

final class RawAndBlobTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private const string TEXT = "<?php\n\necho 'Grüße';\n";

    private KernelBrowser $client;

    private GitRepositoryBuilder $source;

    private string $repositoryId;

    private string $reviewId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        // Git's binary check reads the first 8,000 bytes, so the NUL of late-nul.txt keeps it text.
        $this->source->commit(['src/App.php' => self::TEXT, 'late-nul.txt' => str_repeat('a', 8000)."\0", 'image.bin' => "\0PNG"]);
        $this->client = static::createClient();
        self::signIn($this->client);
        $this->repositoryId = self::connectAndClone($this->client);
        $this->reviewId = (string) self::startReviewThroughApi($this->client, $this->repositoryId)['id'];
    }

    #[TestDox('raw returns the bytes of git with nosniff and sandbox headers')]
    public function testRawReturnsBytesWithHeaders(): void
    {
        foreach (['src/App.php' => 'text/plain; charset=utf-8', 'late-nul.txt' => 'text/plain; charset=utf-8', 'image.bin' => 'application/octet-stream'] as $path => $contentType) {
            $response = $this->raw($path);
            $bytes = $this->source->git('cat-file', 'blob', "main:{$path}");

            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertSame($bytes, $this->client->getInternalResponse()->getContent(), $path);
            self::assertSame($contentType, $response->headers->get('Content-Type'), $path);
            self::assertSame((string) strlen($bytes), $response->headers->get('Content-Length'), $path);
            self::assertSame('"'.$this->source->revParse("main:{$path}").'"', $response->headers->get('ETag'), $path);
            self::assertCacheAndSafetyHeaders($response);
        }
    }

    #[TestDox('raw answers a matching If-None-Match with 304')]
    public function testRawAnswersIfNoneMatch(): void
    {
        $blobSha = $this->source->revParse('main:src/App.php');

        $response = $this->raw('src/App.php', ['HTTP_IF_NONE_MATCH' => "W/\"{$blobSha}\""]);

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', $this->client->getInternalResponse()->getContent());
        self::assertSame("\"{$blobSha}\"", $response->headers->get('ETag'));
        self::assertCacheAndSafetyHeaders($response);
    }

    #[TestDox('blobs returns a blob of the clone and 404 for an unknown object')]
    public function testBlobsReturnsBlobAnd404ForUnknownObject(): void
    {
        $blobSha = $this->source->revParse('main:src/App.php');
        $blobs = "/api/v1/repositories/{$this->repositoryId}/blobs";

        $response = self::apiRequest($this->client, 'GET', "{$blobs}/{$blobSha}");

        self::assertSame([200, self::TEXT, "\"{$blobSha}\""], [$response->getStatusCode(), $this->client->getInternalResponse()->getContent(), $response->headers->get('ETag')]);
        self::assertCacheAndSafetyHeaders($response);
        self::assertApiError(self::apiRequest($this->client, 'GET', "{$blobs}/".str_repeat('d', 40)), 404, 'not_found');
        self::assertApiError(self::apiRequest($this->client, 'GET', "{$blobs}/".$this->source->revParse('main:src')), 404, 'not_found');
    }

    /**
     * @param array<string, string> $server
     */
    private function raw(string $path, array $server = []): Response
    {
        return self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}/raw?path=".rawurlencode($path), server: $server);
    }

    private static function assertCacheAndSafetyHeaders(Response $response): void
    {
        self::assertSame('immutable, max-age=31536000, private', $response->headers->get('Cache-Control'));
        self::assertSame('Authorization, Cookie', $response->headers->get('Vary'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame("sandbox; default-src 'none'", $response->headers->get('Content-Security-Policy'));
    }
}
