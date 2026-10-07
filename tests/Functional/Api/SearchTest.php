<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

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

final class SearchTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private const array FILES = [
        'src/Billing/Money.php' => "<?php\n\nnamespace App\\Billing;\n\nfinal class Money\n{\n}\n",
        'src/Billing/MoneyFormatter.php' => "<?php\n\nnamespace App\\Billing;\n\nfinal class MoneyFormatter\n{\n}\n",
        'src/Billing/Invoice.php' => "<?php\n\nnamespace App\\Billing;\n\nfinal class Invoice\n{\n    public function toMoney(): Money\n    {\n        return new Money();\n    }\n}\n",
        'docs/money/README.md' => "# Money\n",
    ];

    private KernelBrowser $client;

    private string $reviewId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $source = $this->newSource();
        $source->commit(self::FILES);
        $source->git('update-index', '--add', '--cacheinfo', '160000,'.str_repeat('5', 40).',vendor/money');
        $source->git('commit', '--quiet', '--message=Submodule');
        $this->client = static::createClient();
        self::signIn($this->client);
        $this->reviewId = (string) self::startReviewThroughApi($this->client, self::connectAndClone($this->client))['id'];
    }

    #[TestDox('File and symbol hits come in rank order, without submodules')]
    public function testHitsComeInRankOrder(): void
    {
        self::runQueuedMessages();

        self::assertSame([
            'reviewId' => $this->reviewId,
            'partial' => false,
            'items' => [
                ['kind' => 'symbol', 'path' => 'src/Billing/Money.php', 'fqn' => 'App\Billing\Money', 'symbolKind' => 'class', 'declarationStartLine' => 5],
                ['kind' => 'file', 'path' => 'src/Billing/Money.php'],
                ['kind' => 'file', 'path' => 'src/Billing/MoneyFormatter.php'],
                ['kind' => 'symbol', 'path' => 'src/Billing/MoneyFormatter.php', 'fqn' => 'App\Billing\MoneyFormatter', 'symbolKind' => 'class', 'declarationStartLine' => 5],
                ['kind' => 'symbol', 'path' => 'src/Billing/Invoice.php', 'fqn' => 'App\Billing\Invoice::toMoney()', 'symbolKind' => 'method', 'declarationStartLine' => 7],
                ['kind' => 'file', 'path' => 'docs/money/README.md'],
            ],
        ], $this->search('MONEY'));
    }

    #[TestDox('The result is partial while the index is not ready')]
    public function testPartialWhileTheIndexIsNotReady(): void
    {
        self::assertSame(['reviewId' => $this->reviewId, 'partial' => true, 'items' => []], $this->search('Money'));
    }

    #[TestDox('An empty query gives 422')]
    public function testEmptyQueryGives422(): void
    {
        $error = self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}/search?q="), 422, 'invalid_input');

        self::assertSame(['q'], array_column($error['details']['violations'] ?? [], 'field'));
    }

    #[TestDox('A query with a NUL byte gives 422')]
    public function testNulByteGives422(): void
    {
        $error = self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}/search?q=Money%00x"), 422, 'invalid_input');

        self::assertSame(['q'], array_column($error['details']['violations'] ?? [], 'field'));
    }

    /**
     * @return array<string, mixed> The SearchHitList
     */
    private function search(string $query): array
    {
        return self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}/search?".http_build_query(['q' => $query])), 200, 'lists.schema.json#/$defs/SearchHitList');
    }
}
