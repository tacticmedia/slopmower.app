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
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SymbolsApiTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private const string TOTALS = "<?php\n\nnamespace App\\Billing;\n\nfinal class InvoiceTotals\n{\n    /**\n     * Sum of all lines.\n     */\n    public function total(): int\n    {\n        return 0;\n    }\n}\n";

    private KernelBrowser $client;

    private GitRepositoryBuilder $source;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        $this->source->commit(['src/Billing/InvoiceTotals.php' => self::TOTALS, 'src/Broken.php' => "<?php\n\nfinal class Broken\n{\n"]);
        $this->client = static::createClient();
        self::signIn($this->client);
    }

    #[TestDox('Returns the symbols of an indexed blob')]
    public function testReturnsSymbolsOfAnIndexedBlob(): void
    {
        $review = $this->startReview();
        self::runQueuedMessages();

        $symbols = $this->symbols($review, 'src/Billing/InvoiceTotals.php');

        self::assertSame([
            'reviewId' => $review['id'],
            'path' => 'src/Billing/InvoiceTotals.php',
            'blobSha' => $this->source->revParse('main:src/Billing/InvoiceTotals.php'),
            'language' => 'php',
            'parseError' => false,
            'items' => [
                ['kind' => 'class', 'fqn' => 'App\Billing\InvoiceTotals', 'name' => 'InvoiceTotals', 'parentFqn' => null, 'range' => ['startLine' => 5, 'endLine' => 14], 'declarationStartLine' => 5, 'docStartLine' => null],
                ['kind' => 'method', 'fqn' => 'App\Billing\InvoiceTotals::total()', 'name' => 'total', 'parentFqn' => 'App\Billing\InvoiceTotals', 'range' => ['startLine' => 7, 'endLine' => 13], 'declarationStartLine' => 10, 'docStartLine' => 7],
            ],
        ], $symbols);
    }

    #[TestDox('Parses a blob that is not indexed yet and stores the result')]
    public function testParsesABlobThatIsNotIndexedYet(): void
    {
        $review = $this->startReview();
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM indexed_blob'), 'The index job has not run.');

        $symbols = $this->symbols($review, 'src/Billing/InvoiceTotals.php');

        self::assertCount(2, $symbols['items']);
        self::assertSame([$this->source->revParse('main:src/Billing/InvoiceTotals.php')], $connection->fetchFirstColumn('SELECT blob_sha FROM indexed_blob'));
        self::assertSame(['App\Billing\InvoiceTotals', 'App\Billing\InvoiceTotals::total()'], $connection->fetchFirstColumn('SELECT fqn FROM symbol ORDER BY start_line'));
    }

    #[TestDox('A non-PHP file gives language null and no items')]
    public function testNonPhpFileGivesNoItems(): void
    {
        $symbols = $this->symbols($this->startReview(), 'README.md');

        self::assertSame([null, false, []], [$symbols['language'], $symbols['parseError'], $symbols['items']]);
    }

    #[TestDox('A parse error gives parseError true')]
    public function testParseErrorGivesParseErrorTrue(): void
    {
        $review = $this->startReview();
        self::runQueuedMessages();

        $symbols = $this->symbols($review, 'src/Broken.php');

        self::assertSame(['php', true, []], [$symbols['language'], $symbols['parseError'], $symbols['items']]);
    }

    #[TestDox('A matching If-None-Match gives 304')]
    public function testMatchingIfNoneMatchGives304(): void
    {
        $review = $this->startReview();
        $uri = "/api/v1/reviews/{$review['id']}/symbols?path=src%2FBilling%2FInvoiceTotals.php";
        $etag = 'W/"v1-'.$this->source->revParse('main:src/Billing/InvoiceTotals.php').'"';

        $first = self::apiRequest($this->client, 'GET', $uri);
        $notModified = self::apiRequest($this->client, 'GET', $uri, server: ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertSame([200, 304, ''], [$first->getStatusCode(), $notModified->getStatusCode(), (string) $notModified->getContent()]);
        foreach ([$first, $notModified] as $response) {
            self::assertSame($etag, $response->headers->get('ETag'));
            self::assertSame('no-cache, private', $response->headers->get('Cache-Control'));
        }
    }

    #[TestDox('The index endpoint returns the IndexStatus')]
    public function testIndexEndpointReturnsTheIndexStatus(): void
    {
        $review = $this->startReview();
        self::assertIsArray($review['index']);
        self::assertSame('pending', $review['index']['state']);
        // The next request reboots the kernel, which empties the in-memory queue.
        self::runQueuedMessages();

        $status = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$review['id']}/index"), 200, 'review.schema.json#/$defs/IndexStatus');
        $reviewNow = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$review['id']}"), 200, 'review.schema.json');

        self::assertSame(['ready', 3, 3, 2, null], [$status['state'], $status['filesTotal'], $status['filesDone'], $status['symbolsTotal'], $status['error']]);
        self::assertSame($reviewNow['index'], $status);
    }

    /**
     * @return array<string, mixed> The Review, with its index job queued
     */
    private function startReview(): array
    {
        return self::startReviewThroughApi($this->client, self::connectAndClone($this->client));
    }

    /**
     * @param array<string, mixed> $review
     *
     * @return array<string, mixed> The SymbolList
     */
    private function symbols(array $review, string $path): array
    {
        return self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$review['id']}/symbols?path=".rawurlencode($path)), 200, 'lists.schema.json#/$defs/SymbolList');
    }
}
