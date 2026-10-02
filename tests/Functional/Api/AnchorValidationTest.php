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

final class AnchorValidationTest extends WebTestCase
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

    private const string LATIN_1_FILE = 'docs/latin-1.txt';

    private KernelBrowser $client;

    private string $reviewId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit([...self::codeFiles(), self::LATIN_1_FILE => "Caf\xE9 au lait\n"]);
        $this->client = static::createClient();
        self::signIn($this->client);
        $this->reviewId = self::startCodeReview($this->client);
    }

    #[TestDox('A line anchor on a text file that is not UTF-8 gives 422')]
    public function testLineAnchorOnNonUtf8FileGivesInvalidInput(): void
    {
        self::assertSame(['anchor.path'], $this->violatedFields(['kind' => 'line', 'path' => self::LATIN_1_FILE, 'range' => ['startLine' => 1, 'endLine' => 1]]));
    }

    #[TestDox('A symbol on a line, range, or file anchor gives 422')]
    public function testSymbolOnOtherKindGivesInvalidInput(): void
    {
        $symbol = ['fqn' => 'App\Billing\InvoiceTotals::total()'];

        self::assertSame(['anchor.symbol'], $this->violatedFields(['kind' => 'line', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 3, 'endLine' => 3], 'symbol' => $symbol]));
        self::assertSame(['anchor.symbol'], $this->violatedFields(['kind' => 'file', 'path' => self::INVOICE_TOTALS, 'symbol' => $symbol]));
    }

    #[TestDox('A symbol anchor without a symbol or with a range gives 422')]
    public function testSymbolAnchorWithoutSymbolOrWithRangeGivesInvalidInput(): void
    {
        self::assertSame(['anchor.symbol'], $this->violatedFields(['kind' => 'symbol', 'path' => 'src/Billing/TaxRounding.php']));
        self::assertSame(['anchor.range'], $this->violatedFields(['kind' => 'symbol', 'path' => 'src/Billing/TaxRounding.php', 'range' => ['startLine' => 3, 'endLine' => 5], 'symbol' => ['fqn' => 'TaxRounding']]));
    }

    #[TestDox('A line outside the file gives 422 invalid_input')]
    public function testLineOutsideFileGivesInvalidInput(): void
    {
        self::assertSame(['anchor.range.endLine'], $this->violatedFields(['kind' => 'line', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 61, 'endLine' => 61]]));
    }

    #[TestDox('A start after the end, a line anchor with two lines, and a range anchor with one line give 422')]
    public function testInconsistentRangesGiveInvalidInput(): void
    {
        $fields = array_map($this->violatedFields(...), [
            ['kind' => 'range', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 9, 'endLine' => 8]],
            ['kind' => 'line', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 8, 'endLine' => 9]],
            ['kind' => 'range', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 8, 'endLine' => 8]],
        ]);

        self::assertSame([['anchor.range.endLine'], ['anchor.range.endLine'], ['anchor.range.endLine']], $fields);
    }

    #[TestDox('A missing path gives 422')]
    public function testMissingPathGivesInvalidInput(): void
    {
        self::assertSame(['anchor.path'], $this->violatedFields(['kind' => 'file']));
        self::assertSame(['anchor.path'], $this->violatedFields(['kind' => 'file', 'path' => 'src/Billing/Missing.php']));
    }

    #[TestDox('A line anchor on a binary file gives 422, and a file anchor on it works')]
    public function testLineAnchorOnBinaryFileGivesInvalidInput(): void
    {
        self::assertSame(['anchor.path'], $this->violatedFields(['kind' => 'line', 'path' => 'assets/logo.png', 'range' => ['startLine' => 1, 'endLine' => 1]]));
        self::assertSame('file', self::openRequest($this->client, $this->reviewId, ['kind' => 'file', 'path' => 'assets/logo.png'])['anchor']['kind']);
    }

    /**
     * @param array<string, mixed> $anchor
     *
     * @return list<string>
     */
    private function violatedFields(array $anchor): array
    {
        $response = self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/requests", ['anchor' => $anchor, 'body' => 'Use `Money`.']);
        $error = self::assertApiError($response, 422, 'invalid_input');
        self::assertNotNull($error['details']);

        return array_column($error['details']['violations'], 'field');
    }
}
