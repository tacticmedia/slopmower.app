<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\CodeComments;
use App\Tests\Support\Git\FixtureRepositories;
use Facebook\WebDriver\Interactions\WebDriverActions;
use Facebook\WebDriver\WebDriverKeys;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class ExactRangeBecomesSymbolTest extends PantherTestCase
{
    use CodeComments;
    use ResetDatabase;

    private const string ROUND_FQN = 'App\Billing\TaxRounding::round()';

    #[TestDox("Selecting exactly a method's range creates a symbol anchor with its FQN")]
    public function testSelectingExactlyAMethodRangeCreatesASymbolAnchor(): void
    {
        $client = $this->browserAtFile(FixtureRepositories::SYMBOL_FILE);
        self::waitForSymbols($client);
        self::waitForHighlighting($client, 17);

        self::pointerGesture($client, static function (WebDriverActions $actions, callable $find): void {
            $actions->click($find(self::lineNumber(11)))->keyDown(null, WebDriverKeys::SHIFT)->click($find(self::lineNumber(17)))->keyUp(null, WebDriverKeys::SHIFT)->perform();
        });
        self::dragFromPlus($client, 17, 17);
        $textarea = self::focusedCommentBoxBelow($client, 17);
        $header = self::commentBoxHeader($client, 17);
        self::addSingleComment($client, $textarea, 'Round half to even.', '[slot="annotation-17"]');

        self::assertSame('Comment on method '.self::ROUND_FQN, $header);
        self::assertSame([['kind' => 'symbol', 'path' => FixtureRepositories::SYMBOL_FILE, 'range' => [11, 17], 'body' => 'Round half to even.']], $this->savedRequests());
        self::assertSame([self::ROUND_FQN], $this->savedSymbolFqns());
    }
}
