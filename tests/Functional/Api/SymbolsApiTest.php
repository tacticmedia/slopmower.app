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

    private const string TOTALS_TS = "/**\n * Sums invoice lines.\n */\nexport class InvoiceTotals {\n    total(): number {\n        return 0;\n    }\n}\n";

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
        $etag = 'W/"v5-'.$this->source->revParse('main:src/Billing/InvoiceTotals.php').'"';

        $first = self::apiRequest($this->client, 'GET', $uri);
        $notModified = self::apiRequest($this->client, 'GET', $uri, server: ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertSame([200, 304, ''], [$first->getStatusCode(), $notModified->getStatusCode(), (string) $notModified->getContent()]);
        foreach ([$first, $notModified] as $response) {
            self::assertSame($etag, $response->headers->get('ETag'));
            self::assertSame('no-cache, private', $response->headers->get('Cache-Control'));
        }
    }

    #[TestDox('A TypeScript file gives its language, its symbols, and the ETag of V9')]
    public function testTypeScriptFileGivesItsSymbols(): void
    {
        $this->source->commit(['assets/totals.ts' => self::TOTALS_TS]);
        $review = $this->startReview();

        $response = self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$review['id']}/symbols?path=assets%2Ftotals.ts");
        $symbols = self::assertApiResponse($response, 200, 'lists.schema.json#/$defs/SymbolList');

        self::assertSame('W/"v5-'.$this->source->revParse('main:assets/totals.ts').'"', $response->headers->get('ETag'));
        self::assertSame(['typescript', false], [$symbols['language'], $symbols['parseError']]);
        self::assertSame([
            ['kind' => 'class', 'fqn' => 'InvoiceTotals', 'name' => 'InvoiceTotals', 'parentFqn' => null, 'range' => ['startLine' => 1, 'endLine' => 8], 'declarationStartLine' => 4, 'docStartLine' => 1],
            ['kind' => 'method', 'fqn' => 'InvoiceTotals.total()', 'name' => 'total', 'parentFqn' => 'InvoiceTotals', 'range' => ['startLine' => 5, 'endLine' => 7], 'declarationStartLine' => 5, 'docStartLine' => null],
        ], $symbols['items']);
    }

    #[TestDox('A file of each other tree-sitter language gives its language, its symbols, and the ETag of V9')]
    public function testFileOfEachOtherTreeSitterLanguageGivesItsSymbols(): void
    {
        $this->source->commit([
            'tools/totals.py' => "class Totals:\n    @property\n    def total(self) -> int:\n        return 0\n",
            'billing/totals.go' => "package billing\n\n// Sum adds the lines.\nfunc (t *Totals[T]) Sum() int { return 0 }\n",
            'src/totals.rs' => "impl From<i64> for Total {\n    fn from(cents: i64) -> Self {\n        Total\n    }\n}\n",
            'java/Totals.java' => "class Totals {\n    long total() {\n        return 0;\n    }\n}\n",
            'dotnet/Totals.cs' => "namespace Billing;\n\npublic class Totals\n{\n    public long Count { get; }\n}\n",
            'lib/totals.rb' => "module Billing\n  class Totals\n    def self.build; end\n  end\nend\n",
            'bin/release.sh' => "#!/bin/sh\nfunction release {\n    echo 1\n}\n",
            'scala/Totals.scala' => "object Totals {\n  def empty: Long = 0\n}\n",
            'zig/totals.zig' => "pub const Totals = struct {\n    cents: i64,\n};\n",
            'lua/totals.lua' => "function M:count()\n  return 0\nend\n",
        ]);
        $review = $this->startReview();
        $expected = [
            'tools/totals.py' => ['python', [['class', 'Totals', null], ['method', 'Totals.total()', 'Totals']]],
            'billing/totals.go' => ['go', [['method', 'Totals.Sum()', 'Totals']]],
            'src/totals.rs' => ['rust', [['method', '<Total as From<i64>>::from()', 'Total']]],
            'java/Totals.java' => ['java', [['class', 'Totals', null], ['method', 'Totals.total()', 'Totals']]],
            'dotnet/Totals.cs' => ['csharp', [['class', 'Totals', null], ['property', 'Totals.Count', 'Totals']]],
            'lib/totals.rb' => ['ruby', [['module', 'Billing', null], ['class', 'Billing::Totals', 'Billing'], ['method', 'Billing::Totals::build()', 'Billing::Totals']]],
            'bin/release.sh' => ['shellscript', [['function', 'release()', null]]],
            'scala/Totals.scala' => ['scala', [['module', 'Totals', null], ['method', 'Totals.empty()', 'Totals']]],
            'zig/totals.zig' => ['zig', [['struct', 'Totals', null], ['property', 'Totals.cents', 'Totals']]],
            'lua/totals.lua' => ['lua', [['method', 'M.count()', 'M']]],
        ];

        foreach ($expected as $path => [$language, $items]) {
            $response = self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$review['id']}/symbols?path=".rawurlencode($path));
            $symbols = self::assertApiResponse($response, 200, 'lists.schema.json#/$defs/SymbolList');

            self::assertSame('W/"v5-'.$this->source->revParse("main:{$path}").'"', $response->headers->get('ETag'));
            self::assertSame([$language, false, $items], [
                $symbols['language'],
                $symbols['parseError'],
                array_map(static fn (array $item): array => [$item['kind'], $item['fqn'], $item['parentFqn']], $symbols['items']),
            ]);
        }
    }

    #[TestDox('A Twig file gives language twig, its blocks and macros, and the ETag of V9')]
    public function testTwigFileGivesItsSymbols(): void
    {
        $this->source->commit(['templates/invoice.html.twig' => "{## The page. #}\n{% block body %}\n    {{ _self.row(1)|money_format }}\n{% endblock %}\n\n{% macro row(cents) %}{{ cents }}{% endmacro %}\n"]);
        $review = $this->startReview();

        $response = self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$review['id']}/symbols?path=templates%2Finvoice.html.twig");
        $symbols = self::assertApiResponse($response, 200, 'lists.schema.json#/$defs/SymbolList');

        self::assertSame('W/"v5-'.$this->source->revParse('main:templates/invoice.html.twig').'"', $response->headers->get('ETag'));
        self::assertSame(['twig', false], [$symbols['language'], $symbols['parseError']]);
        self::assertSame([
            ['kind' => 'block', 'fqn' => 'body', 'name' => 'body', 'parentFqn' => null, 'range' => ['startLine' => 1, 'endLine' => 4], 'declarationStartLine' => 2, 'docStartLine' => 1],
            ['kind' => 'function', 'fqn' => 'row()', 'name' => 'row', 'parentFqn' => null, 'range' => ['startLine' => 6, 'endLine' => 6], 'declarationStartLine' => 6, 'docStartLine' => null],
        ], $symbols['items']);
    }

    #[TestDox('Identical bytes at a .js and a .ts path give each its own language, and one indexed_blob row')]
    public function testIdenticalBytesInTwoLanguagesGiveTwoAnswers(): void
    {
        $this->source->commit(['assets/a.js' => "export function a() {}\n", 'assets/a.ts' => "export function a() {}\n"]);
        $review = $this->startReview();

        $languages = array_map(fn (string $path): mixed => $this->symbols($review, $path)['language'], ['assets/a.js', 'assets/a.ts', 'assets/a.js']);

        self::assertSame(['javascript', 'typescript', 'javascript'], $languages);
        self::assertSame([['language' => 'javascript']], self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT language FROM indexed_blob WHERE blob_sha = ?', [$this->source->revParse('main:assets/a.ts')]));
    }

    #[TestDox('With the symbols service down, a TypeScript file gives 500 and stores nothing, and a PHP file still works')]
    public function testServiceDownFailsOnlyTheFilesOfTheService(): void
    {
        $this->source->commit(['assets/totals.ts' => self::TOTALS_TS]);
        $review = $this->startReview();
        $this->setEnvironmentVariable('SYMBOLS_URL', 'http://symbols:9');

        self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$review['id']}/symbols?path=assets%2Ftotals.ts"), 500, 'internal_error');
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM indexed_blob'));
        self::assertSame('php', $this->symbols($review, 'src/Billing/InvoiceTotals.php')['language']);
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
