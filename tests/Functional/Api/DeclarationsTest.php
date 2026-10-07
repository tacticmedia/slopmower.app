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

final class DeclarationsTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private const string TOTALS = 'src/Billing/InvoiceTotals.php';

    private const string LONG = 'src/Long.php';

    private const array FILES = [
        self::TOTALS => "<?php\n\nnamespace App\\Billing;\n\nuse App\\Billing\\Tax\\TaxRate;\n\nfinal class InvoiceTotals\n{\n    public function total(int \$net): int\n    {\n        return round_half_up(\$net * new TaxRate()->basisPoints);\n    }\n}\n",
        'src/Billing/Tax/TaxRate.php' => "<?php\n\nnamespace App\\Billing\\Tax;\n\n/**\n * Basis points.\n */\nfinal class TaxRate\n{\n    public int \$basisPoints = 1000;\n}\n",
        'src/functions.php' => "<?php\n\nfunction round_half_up(int \$value): int\n{\n    return \$value;\n}\n",
        'src/Billing/Money.php' => "<?php\n\nnamespace App\\Billing;\n\nfinal class Money\n{\n}\n",
        'lib/Money.php' => "<?php\n\nnamespace App\\Billing;\n\nfinal class Money\n{\n}\n",
        'src/Foo.php' => "<?php\n\nfinal class Foo\n{\n}\n",
        'assets/Foo.ts' => "export class Foo {}\n",
    ];

    private KernelBrowser $client;

    private string $reviewId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit([...self::FILES, self::LONG => "<?php\n\nnamespace App;\n\nfinal class ".self::longName()."\n{\n}\n"]);
        $this->client = static::createClient();
        self::signIn($this->client);
        $this->reviewId = (string) self::startReviewThroughApi($this->client, self::connectAndClone($this->client))['id'];
        self::runQueuedMessages();
    }

    #[TestDox('A class name resolves to its file and range')]
    public function testClassNameResolvesToItsFileAndRange(): void
    {
        self::assertSame([
            'reviewId' => $this->reviewId,
            'items' => [['path' => 'src/Billing/Tax/TaxRate.php', 'fqn' => 'App\Billing\Tax\TaxRate', 'kind' => 'class', 'range' => ['startLine' => 5, 'endLine' => 11], 'declarationStartLine' => 8]],
        ], $this->declarations(['line' => 11, 'column' => self::columnOf('TaxRate()')]));
    }

    #[TestDox('An unqualified function call falls back to the global function')]
    public function testFunctionCallFallsBackToTheGlobalFunction(): void
    {
        self::assertSame(
            [['path' => 'src/functions.php', 'fqn' => 'round_half_up()', 'kind' => 'function', 'range' => ['startLine' => 3, 'endLine' => 6], 'declarationStartLine' => 3]],
            $this->declarations(['line' => 11, 'column' => self::columnOf('round_half_up')])['items'],
        );
    }

    #[TestDox('An unknown name gives an empty list')]
    public function testUnknownNameGivesAnEmptyList(): void
    {
        self::assertSame([], $this->declarations(['name' => 'Symfony\Component\HttpFoundation\Response'])['items']);
    }

    #[TestDox('Two declarations with one FQN give two targets')]
    public function testTwoDeclarationsGiveTwoTargets(): void
    {
        $items = $this->declarations(['name' => 'Money'])['items'];

        self::assertSame([['lib/Money.php', 'App\Billing\Money'], ['src/Billing/Money.php', 'App\Billing\Money']], array_map(static fn (array $item): array => [$item['path'], $item['fqn']], $items));
    }

    #[TestDox('A TypeScript class with the FQN of a PHP class is no declaration')]
    public function testTypeScriptClassIsNoDeclaration(): void
    {
        $items = $this->declarations(['name' => '\\Foo'])['items'];

        self::assertSame([['src/Foo.php', 'Foo']], array_map(static fn (array $item): array => [$item['path'], $item['fqn']], $items));
    }

    #[TestDox('A long multibyte FQN is indexed and resolves')]
    public function testLongMultibyteFqnResolves(): void
    {
        $fqn = 'App\\'.self::longName();

        self::assertSame([[self::LONG, $fqn]], array_map(static fn (array $item): array => [$item['path'], $item['fqn']], $this->declarations(['path' => self::LONG, 'name' => "\\{$fqn}"])['items']));
    }

    /**
     * 1,000 varied CJK characters of 3 UTF-8 bytes each: a btree entry of the FQN would exceed the PostgreSQL limit of
     * 2,704 bytes, also compressed.
     */
    private static function longName(): string
    {
        return implode('', array_map(static fn (int $index): string => (string) mb_chr(0x4E00 + $index * 7919 % 20000), range(0, 999)));
    }

    /**
     * @return int The column of $code on line 11 of TOTALS
     */
    private static function columnOf(string $code): int
    {
        return (int) strpos(explode("\n", self::FILES[self::TOTALS])[10], $code) + 1;
    }

    /**
     * @param array<string, int|string> $query Besides `path`
     *
     * @return array<string, mixed> The DeclarationList
     */
    private function declarations(array $query): array
    {
        $uri = "/api/v1/reviews/{$this->reviewId}/declarations?".http_build_query(['path' => self::TOTALS, ...$query]);

        return self::assertApiResponse(self::apiRequest($this->client, 'GET', $uri), 200, 'lists.schema.json#/$defs/DeclarationList');
    }
}
