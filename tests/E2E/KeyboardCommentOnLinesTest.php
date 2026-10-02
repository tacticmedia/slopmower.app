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

    private const int MAXIMUM_TABS = 40;

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
     * The recorded result: the library adds the "+" only under the pointer or on the last selected line. There it
     * takes the focus, but it has no accessible name, and Enter and Space do nothing, because the library starts a
     * comment on pointerdown.
     */
    #[TestDox('The focus behaviour of the gutter + button equals the recorded result')]
    public function testGutterPlusFocusBehaviourEqualsTheRecordedResult(): void
    {
        $client = $this->browserAtFile(FixtureRepositories::SMALL_FILE);
        self::waitForHighlighting($client, 3);
        self::assertSame(0, ShadowDom::count($client, '[data-utility-button]'), 'Without a pointer and a selection, no "+" exists.');

        $this->openFile($client, FixtureRepositories::SMALL_FILE, '#L3');
        ShadowDom::waitUntil($client, '[data-column-number="3"][data-selected-line] [data-utility-button]', static fn (): bool => true);
        self::tabUntil($client, self::PLUS_HAS_FOCUS, 'the gutter "+"');
        $name = ShadowDom::waitUntil($client, '[data-utility-button]', static fn (WebDriverElement $plus): string => ($plus->getAttribute('aria-label') ?? '').$plus->getText());
        self::assertSame('', $name, 'The "+" has no accessible name.');

        $client->getKeyboard()->sendKeys(WebDriverKeys::ENTER);
        $client->getKeyboard()->sendKeys(WebDriverKeys::SPACE);
        self::waitForFrames($client);

        self::assertSelectorNotExists(self::COMPOSER);
        self::assertSame([], $this->savedRequests());
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

    /**
     * Types into the focused comment box, then tabs to "Add single comment" and presses Enter.
     */
    private static function keyboardComment(Client $client, WebDriverElement $textarea, string $body, string $container): void
    {
        $textarea->sendKeys($body);
        self::tabToButton($client, 'Add single comment');
        $client->getKeyboard()->sendKeys(WebDriverKeys::ENTER);
        self::waitForSavedThread($client, $container, $body);
    }

    private static function tabToButton(Client $client, string $text): void
    {
        self::tabUntil($client, 'return "BUTTON" === document.activeElement?.tagName && arguments[0] === document.activeElement.textContent.trim();', "the \"{$text}\" button", [$text]);
    }

    /**
     * @param list<string> $arguments
     */
    private static function tabUntil(Client $client, string $focusCheck, string $target, array $arguments = []): void
    {
        for ($tabs = 0; $tabs < self::MAXIMUM_TABS; ++$tabs) {
            if (true === $client->executeScript($focusCheck, $arguments)) {
                return;
            }
            $client->getKeyboard()->sendKeys(WebDriverKeys::TAB);
        }

        self::fail("Tab did not reach {$target}.");
    }

    /**
     * A key press that opened a comment box would have rendered it by then.
     */
    private static function waitForFrames(Client $client): void
    {
        $client->executeAsyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            let frames = 10;
            const next = () => (0 === --frames ? done() : requestAnimationFrame(next));
            requestAnimationFrame(next);
            JS);
    }
}
