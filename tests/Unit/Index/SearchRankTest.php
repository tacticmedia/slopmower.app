<?php

declare(strict_types=1);

namespace App\Tests\Unit\Index;

use App\Index\SearchRank;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SearchRankTest extends KernelTestCase
{
    #[TestDox('A name matches a query of word prefixes in word order, ignoring case')]
    #[DataProvider('wordStartMatches')]
    public function testWordStartMatch(string $name, string $query, bool $matches): void
    {
        self::assertSame($matches, SearchRank::wordStartsMatcher($query)($name));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function wordStartMatches(): iterable
    {
        yield 'CE' => ['CommandExecutor', 'CE', true];
        yield 'ComEx' => ['CommandExecutor', 'ComEx', true];
        yield 'HC' => ['HTTPClient', 'HC', true];
        yield 'ReqDec' => ['RequestDecider', 'ReqDec', true];
        yield 'tax' => ['InvoiceTax', 'tax', true];
        yield 'ce in Service' => ['Service', 'ce', false];
        yield 'C-E' => ['Command-Executor', 'C-E', false];
        yield 'a shorter prefix than the longest' => ['TreeEntry', 'tren', true];
        yield 'a word that starts with a non-ASCII letter' => ['GrößeÄndern', 'gä', true];
    }

    #[TestDox('Word starts rank after a name prefix and before a name substring, whatever the name length')]
    public function testWordStartsRankBetweenPrefixAndSubstring(): void
    {
        $candidates = [self::symbol('Space', 'src/A.php'), self::symbol('CodeEditor', 'src/A.php'), self::symbol('Certificate', 'src/A.php')];

        self::assertSame(['Certificate', 'CodeEditor', 'Space'], array_column(SearchRank::top($candidates, 'ce', 10), 'name'));
    }

    #[TestDox('A shorter name ranks before a longer name in a shorter path')]
    public function testNameLengthRanksBeforePathLength(): void
    {
        $candidates = [self::symbol('MoneyFormatter', 'src/M.php'), self::symbol('MoneyBag', 'src/Billing/Wallet/MoneyBag.php')];

        self::assertSame(['MoneyBag', 'MoneyFormatter'], array_column(SearchRank::top($candidates, 'money', 10), 'name'));
    }

    #[TestDox('Candidates in any order give the first hits of a full sort, also when each group holds more than the limit')]
    #[DataProvider('limits')]
    public function testBoundedGroupsGiveTheFullSort(int $limit): void
    {
        $candidates = [];

        foreach (range(10, 18) as $i) {
            array_push(
                $candidates,
                self::symbol('Ab', "src/Equal{$i}.php"),
                self::symbol("Ab{$i}", 'src/Prefix.php'),
                self::symbol("XyAb{$i}", 'src/WordStarts.php'),
                self::symbol("Xab{$i}", 'src/Substring.php'),
                ['kind' => 'file', 'path' => "ab{$i}/Readme.md", 'name' => 'Readme.md', 'fqn' => null, 'symbol_kind' => null, 'declaration_start_line' => null],
                self::symbol("Zz{$i}", 'src/Other.php'),
            );
        }

        $shuffled = new Randomizer(new Mt19937(22))->shuffleArray($candidates);
        $fullSort = SearchRank::top($candidates, 'ab', count($candidates));

        self::assertCount(45, $fullSort);
        self::assertSame(array_slice($fullSort, 0, $limit), SearchRank::top($shuffled, 'ab', $limit));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function limits(): iterable
    {
        yield 'more candidates than the limit in each group' => [4];
        yield 'hits from three groups' => [20];
    }

    /**
     * @return array<string, mixed>
     */
    private static function symbol(string $name, string $path): array
    {
        return ['kind' => 'symbol', 'path' => $path, 'name' => $name, 'fqn' => 'App\\'.$name, 'symbol_kind' => 'class', 'declaration_start_line' => 1];
    }
}
