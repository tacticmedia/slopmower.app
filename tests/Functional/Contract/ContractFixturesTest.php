<?php

declare(strict_types=1);

namespace App\Tests\Functional\Contract;

use App\Tests\Support\Contract\SchemaValidator;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ContractFixturesTest extends KernelTestCase
{
    private const string SPECS_DIRECTORY = __DIR__.'/../../../docs/specs';

    #[TestDox('Every docs/specs fixture validates against its schema')]
    public function testEveryFixtureMatchesItsSchema(): void
    {
        $schemaByFixture = self::fixtureTable();
        $fixtureFiles = array_map(basename(...), glob(self::SPECS_DIRECTORY.'/fixtures/*.json') ?: []);
        sort($fixtureFiles);
        $tableFiles = array_keys($schemaByFixture);
        sort($tableFiles);

        self::assertSame($fixtureFiles, $tableFiles, 'The fixture table of docs/specs/README.md section 11 lists every fixture file.');

        foreach ($schemaByFixture as $fixture => $schema) {
            $json = (string) file_get_contents(self::SPECS_DIRECTORY."/fixtures/{$fixture}");
            self::assertSame([], SchemaValidator::errors($json, $schema), "{$fixture} does not match {$schema}.");
        }
    }

    /**
     * @return array<string, string> Fixture file to schema reference
     */
    private static function fixtureTable(): array
    {
        $readme = (string) file_get_contents(self::SPECS_DIRECTORY.'/README.md');
        preg_match_all('~^\| \[([^]]+)]\(fixtures/[^)]+\) \| `([^`]+)` \|~m', $readme, $rows, \PREG_SET_ORDER);

        return array_column($rows, 2, 1);
    }
}
