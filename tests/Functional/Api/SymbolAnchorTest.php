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
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Q2 with `kind: symbol`, decision D7.
 */
final class SymbolAnchorTest extends WebTestCase
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

    private const string TOTAL_FQN = 'App\Billing\InvoiceTotals::total()';

    /** `total()` has the lines 7 to 13, its declaration starts on line 10. */
    private const string TOTALS = <<<'PHP'
        <?php

        namespace App\Billing;

        final class InvoiceTotals
        {
            /**
             * Sum of all lines.
             */
            public function total(): int
            {
                return 0;
            }
        }

        PHP;

    /** Two declarations of `home()`. */
    private const string PLATFORM = <<<'PHP'
        <?php

        if (PHP_OS_FAMILY === 'Windows') {
            function home(): string { return 'C:\\'; }
        } else {
            function home(): string { return '/'; }
        }

        PHP;

    private KernelBrowser $client;

    private string $reviewId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit([self::INVOICE_TOTALS => self::TOTALS, 'src/platform.php' => self::PLATFORM]);
        $this->client = static::createClient();
        self::signIn($this->client);
        $this->reviewId = self::startCodeReview($this->client);
    }

    #[TestDox('A symbol anchor stores the range with the doc comment and the symbol reference')]
    public function testSymbolAnchorStoresRangeWithDocCommentAndSymbolReference(): void
    {
        self::runQueuedMessages();
        $opened = self::openRequest($this->client, $this->reviewId, ['kind' => 'symbol', 'path' => self::INVOICE_TOTALS, 'symbol' => ['fqn' => self::TOTAL_FQN]]);

        $anchor = self::readRequest($this->client, (string) $opened['id'])['anchor'];

        self::assertSame(['symbol', ['startLine' => 7, 'endLine' => 13], ['language' => 'php', 'kind' => 'method', 'fqn' => self::TOTAL_FQN]], [$anchor['kind'], $anchor['range'], $anchor['symbol']]);
        self::assertSame(['    /**', 7], [$anchor['snapshot']['lines'][0], $anchor['snapshot']['lineCount']]);
    }

    #[TestDox('An unknown FQN gives 422 invalid_input')]
    public function testUnknownFqnGivesInvalidInput(): void
    {
        self::assertSame(['anchor.symbol.fqn'], $this->violatedFields(self::INVOICE_TOTALS, 'App\Billing\InvoiceTotals::tax()'));
    }

    #[TestDox('An ambiguous FQN gives 422 invalid_input')]
    public function testAmbiguousFqnGivesInvalidInput(): void
    {
        self::assertSame(['anchor.symbol.fqn'], $this->violatedFields('src/platform.php', 'home()'));
    }

    #[TestDox('A symbol anchor on a blob that the index job has not reached works')]
    public function testSymbolAnchorOnBlobThatIsNotIndexedYetWorks(): void
    {
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM indexed_blob'), 'The index job has not run.');

        $opened = self::openRequest($this->client, $this->reviewId, ['kind' => 'symbol', 'path' => self::INVOICE_TOTALS, 'symbol' => ['fqn' => self::TOTAL_FQN]]);

        self::assertSame(['startLine' => 7, 'endLine' => 13], $opened['anchor']['range']);
    }

    #[TestDox('A draft with a symbol anchor works')]
    public function testDraftWithSymbolAnchorWorks(): void
    {
        $response = self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts", [
            'kind' => 'new_request',
            'anchor' => ['kind' => 'symbol', 'path' => self::INVOICE_TOTALS, 'symbol' => ['fqn' => self::TOTAL_FQN]],
            'body' => 'Use `Money`.',
        ]);

        $anchor = self::assertApiResponse($response, 201, 'draft.schema.json')['anchor'];

        self::assertSame(['symbol', ['startLine' => 7, 'endLine' => 13], ['language' => 'php', 'kind' => 'method', 'fqn' => self::TOTAL_FQN]], [$anchor['kind'], $anchor['range'], $anchor['symbol']]);
    }

    /**
     * @return list<string>
     */
    private function violatedFields(string $path, string $fqn): array
    {
        $anchor = ['kind' => 'symbol', 'path' => $path, 'symbol' => ['fqn' => $fqn]];
        $error = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/requests", ['anchor' => $anchor, 'body' => 'Use `Money`.']), 422, 'invalid_input');
        self::assertNotNull($error['details']);

        return array_column($error['details']['violations'], 'field');
    }
}
