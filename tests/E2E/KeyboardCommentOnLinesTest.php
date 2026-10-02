<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\CodeComments;
use App\Tests\Support\E2E\ShadowDom;
use App\Tests\Support\Git\FixtureRepositories;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverKeys;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

/**
 * Decision D31. Every step uses the keyboard, none uses the mouse.
 */
final class KeyboardCommentOnLinesTest extends PantherTestCase
{
    use CodeComments;
    use ResetDatabase;

    /** True when the focus is on the gutter "+" inside the shadow root of the code view. */
    private const string PLUS_HAS_FOCUS = <<<'JS'
        return document.activeElement?.shadowRoot?.activeElement?.hasAttribute('data-utility-button') ?? false;
        JS;

    #[TestDox('Comment on lines 3 to 4 creates a range request with the keyboard only')]
    public function testCommentOnLinesWithTheKeyboardOnly(): void
    {
        $client = $this->browserAtFile(FixtureRepositories::SMALL_FILE);

        self::commentOnLines($client, 3, 4);
        self::keyboardComment($client, self::focusedCommentBoxBelow($client, 4), 'Merge these lines.', '[slot="annotation-4"]');

        self::assertSame([['kind' => 'range', 'path' => FixtureRepositories::SMALL_FILE, 'range' => [3, 4], 'body' => 'Merge these lines.']], $this->savedRequests());
    }

    #[TestDox('The comment box has focus when it opens')]
    public function testCommentBoxHasFocusWhenItOpens(): void
    {
        $client = $this->browserAtFile(FixtureRepositories::LARGE_FILE);

        // Far below the first rows, so the box renders only after the view scrolls there.
        self::commentOnLines($client, 3000, 3002);

        self::assertSame('Comment on lines 3000 to 3002', self::focusedCommentBoxBelow($client, 3002)->getAttribute('aria-label'));
    }

    #[TestDox('The file comment button works with the keyboard')]
    public function testFileCommentButtonWorksWithTheKeyboard(): void
    {
        $client = $this->browserAtFile(FixtureRepositories::SMALL_FILE);

        self::tabToButton($client, 'Comment on file');
        $client->getKeyboard()->sendKeys(WebDriverKeys::ENTER);
        $textarea = '[data-testid="file-threads"] '.self::COMPOSER;
        $client->waitForVisibility($textarea);
        $client->wait()->until(static fn (): bool => true === $client->executeScript('return document.activeElement === document.querySelector(arguments[0]);', [$textarea]));
        self::keyboardComment($client, $client->getWebDriver()->switchTo()->activeElement(), 'Add a class docblock.', '[data-testid="file-threads"]');

        self::assertSame([['kind' => 'file', 'path' => FixtureRepositories::SMALL_FILE, 'range' => null, 'body' => 'Add a class docblock.']], $this->savedRequests());
    }

    /**
     * The library adds the "+" only under the pointer or on the last selected line, so the keyboard reaches it only on
     * a selection.
     */
    #[TestDox('The gutter + of a selection has a name and opens the comment box with Enter and with Space')]
    public function testGutterPlusOfSelectionHasNameAndOpensCommentBoxWithEnterAndSpace(): void
    {
        $client = $this->browserAtFile(FixtureRepositories::SMALL_FILE);
        self::waitForHighlighting($client, 3);
        self::assertSame(0, ShadowDom::count($client, '[data-utility-button]'), 'Without a pointer and a selection, no "+" exists.');

        $rangeName = $this->pressGutterPlus($client, '#L3-L4', 4, WebDriverKeys::ENTER);
        self::keyboardComment($client, self::focusedCommentBoxBelow($client, 4), 'Join these lines.', '[slot="annotation-4"]');
        $lineName = $this->pressGutterPlus($client, '#L6', 6, WebDriverKeys::SPACE);
        self::keyboardComment($client, self::focusedCommentBoxBelow($client, 6), 'Remove this blank line.', '[slot="annotation-6"]');

        self::assertSame(['Comment on lines 3 to 4', 'Comment on line 6'], [$rangeName, $lineName]);
        self::assertSame(
            [
                ['kind' => 'range', 'path' => FixtureRepositories::SMALL_FILE, 'range' => [3, 4], 'body' => 'Join these lines.'],
                ['kind' => 'line', 'path' => FixtureRepositories::SMALL_FILE, 'range' => [6, 6], 'body' => 'Remove this blank line.'],
            ],
            $this->savedRequests(),
        );
    }

    /**
     * Opens the file at the selection of `$hash`, tabs to the "+" on its last line, and presses `$key`.
     *
     * @return string The accessible name of the "+"
     */
    private function pressGutterPlus(Client $client, string $hash, int $lastLine, string $key): string
    {
        $this->openFile($client, FixtureRepositories::SMALL_FILE, $hash);
        ShadowDom::waitUntil($client, "[data-column-number=\"{$lastLine}\"][data-selected-line] [data-utility-button]", static fn (): bool => true);
        self::tabUntil($client, self::PLUS_HAS_FOCUS, 'the gutter "+"');
        $name = ShadowDom::waitUntil($client, '[data-utility-button]', static fn (WebDriverElement $plus): ?string => $plus->getAttribute('aria-label'));
        $client->getKeyboard()->sendKeys($key);

        return $name;
    }

    private static function commentOnLines(Client $client, int $startLine, int $endLine): void
    {
        self::tabToButton($client, 'Comment on lines');
        $client->getKeyboard()->sendKeys(WebDriverKeys::ENTER);
        $client->wait()->until(static fn (): bool => true === $client->executeScript('return document.activeElement?.name === "start";'));
        $client->getKeyboard()->sendKeys((string) $startLine);
        $client->getKeyboard()->sendKeys(WebDriverKeys::TAB);
        $client->getKeyboard()->sendKeys((string) $endLine);
        $client->getKeyboard()->sendKeys(WebDriverKeys::ENTER);
    }
}
