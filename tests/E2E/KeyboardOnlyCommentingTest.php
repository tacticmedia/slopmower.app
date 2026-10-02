<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\CodeComments;
use App\Tests\Support\Git\FixtureRepositories;
use Facebook\WebDriver\WebDriverKeys;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

/**
 * Decision D31. Every step uses the keyboard, none uses the mouse.
 */
final class KeyboardOnlyCommentingTest extends PantherTestCase
{
    use CodeComments;
    use ResetDatabase;

    private const string ROUND_FQN = 'App\Billing\TaxRounding::round()';

    #[TestDox('The Symbols menu opens, filters, and anchors a comment to a method with the keyboard only')]
    public function testSymbolsMenuAnchorsCommentToMethodWithTheKeyboardOnly(): void
    {
        $client = $this->browserAtFile(FixtureRepositories::SYMBOL_FILE);
        self::waitForSymbols($client);

        self::tabToButton($client, 'Symbols');
        $client->getKeyboard()->sendKeys(WebDriverKeys::ENTER);
        $client->wait()->until(static fn (): bool => true === $client->executeScript('return "Filter symbols" === document.activeElement?.getAttribute("aria-label");'));
        $client->getKeyboard()->sendKeys('round(');
        $client->wait()->until(static fn (): bool => true === $client->executeScript(<<<'JS'
            const options = [...document.querySelectorAll('[role="option"]')];
            return 1 === options.length && options[0].textContent.startsWith('round()');
            JS), 'The filter did not leave round() alone.');
        $client->getKeyboard()->sendKeys(WebDriverKeys::ENTER);
        $textarea = self::focusedCommentBoxBelow($client, 17);
        $header = self::commentBoxHeader($client, 17);
        self::keyboardComment($client, $textarea, 'Round half to even.', '[slot="annotation-17"]');

        self::assertSame('Comment on method '.self::ROUND_FQN, $header);
        self::assertSame([['kind' => 'symbol', 'path' => FixtureRepositories::SYMBOL_FILE, 'range' => [11, 17], 'body' => 'Round half to even.']], $this->savedRequests());
        self::assertSame([self::ROUND_FQN], $this->savedSymbolFqns());
    }
}
