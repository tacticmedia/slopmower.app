<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\CodeComments;
use App\Tests\Support\Git\FixtureRepositories;
use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

final class ReplyAndMarkdownTest extends PantherTestCase
{
    use CodeComments;
    use ResetDatabase;

    private const string THREAD = '[slot="annotation-3"] [data-testid="thread"]';
    private const string REPLY_BOX = self::THREAD.' textarea[aria-label="Reply"]';

    #[TestDox('A reply appears in the thread')]
    public function testReplyAppearsInTheThread(): void
    {
        $client = $this->browserWithSeededThread('Please rename this.');

        $client->getWebDriver()->findElement(WebDriverBy::cssSelector(self::REPLY_BOX))->sendKeys('Renamed in my head already.');
        $client->getWebDriver()->findElement(WebDriverBy::xpath('//button[normalize-space(.)="Reply"]'))->click();

        $client->wait()->until(static fn (): bool => 2 === \count($client->getWebDriver()->findElements(WebDriverBy::cssSelector(self::THREAD.' [data-testid="thread-comment"][aria-busy="false"]'))));
        self::assertSelectorTextContains(self::THREAD, 'Renamed in my head already.');
        self::assertSame('', $client->getWebDriver()->findElement(WebDriverBy::cssSelector(self::REPLY_BOX))->getAttribute('value'), 'A saved reply clears the box.');

        $client->reload();
        $client->waitFor(self::REPLY_BOX);
        self::assertSelectorTextContains(self::THREAD, 'Renamed in my head already.');
    }

    #[TestDox('An unsent reply stays when a comment box opens above its line')]
    public function testUnsentReplyStaysWhenACommentBoxOpensAbove(): void
    {
        $this->codeViewReview();
        $this->seededRequest(10, 10, 'Seeded on line 10.');
        $client = $this->browserAtFile(FixtureRepositories::SMALL_FILE);
        $replyBox = '[slot="annotation-10"] textarea[aria-label="Reply"]';
        $client->waitFor($replyBox);
        self::waitForHighlighting($client, 5);
        $client->getWebDriver()->findElement(WebDriverBy::cssSelector($replyBox))->sendKeys('Half a reply');

        self::dragFromPlus($client, 5, 5);
        self::focusedCommentBoxBelow($client, 5);

        self::assertSame('Half a reply', $client->getWebDriver()->findElement(WebDriverBy::cssSelector($replyBox))->getAttribute('value'));
    }

    #[TestDox('A failed reply keeps its text and shows the error')]
    public function testFailedReplyKeepsItsText(): void
    {
        $client = $this->browserWithSeededThread('Please rename this.');
        // Q4 accepts at most 65536 characters. Typing that many keys takes too long, so the field gets the value.
        $client->executeScript(<<<'JS'
            const field = document.querySelector(arguments[0]);
            Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value').set.call(field, 'x'.repeat(65537));
            field.dispatchEvent(new Event('input', { bubbles: true }));
            JS, [self::REPLY_BOX]);

        $client->getWebDriver()->findElement(WebDriverBy::xpath('//button[normalize-space(.)="Reply"]'))->click();

        $client->waitFor(self::THREAD.' [data-testid="comment-error"]');
        self::assertSame(65537, $client->executeScript('return document.querySelector(arguments[0]).value.length;', [self::REPLY_BOX]));
        self::assertCount(1, $client->getWebDriver()->findElements(WebDriverBy::cssSelector(self::THREAD.' [data-testid="thread-comment"]')));
    }

    #[TestDox('**bold** renders as strong and a script tag shows as text')]
    public function testMarkdownRendersSafely(): void
    {
        $client = $this->browserWithSeededThread("Make this **bold**.\n\n<script>window.injected = true</script>");
        $client->waitFor(self::THREAD.' [data-testid="markdown-body"]');

        self::assertSelectorTextSame(self::THREAD.' [data-testid="markdown-body"] strong', 'bold');
        self::assertSelectorTextContains(self::THREAD.' [data-testid="markdown-body"]', '<script>window.injected = true</script>');
        self::assertSelectorNotExists(self::THREAD.' [data-testid="markdown-body"] script');
        self::assertNull($client->executeScript('return window.injected ?? null;'));
    }

    #[TestDox('"Quote reply" puts the comment as a quote into the reply box')]
    public function testQuoteReplyQuotesTheComment(): void
    {
        $client = $this->browserWithSeededThread("First line.\nSecond line.");

        $client->getWebDriver()->findElement(WebDriverBy::cssSelector(self::THREAD.' [data-testid="thread-comment"] button[aria-haspopup="true"]'))->click();
        $client->waitForVisibility('//li[normalize-space(.)="Quote reply"]');
        $client->getWebDriver()->findElement(WebDriverBy::xpath('//li[normalize-space(.)="Quote reply"]'))->click();

        $client->wait()->until(static fn (): bool => true === $client->executeScript('return document.activeElement === document.querySelector(arguments[0]);', [self::REPLY_BOX]));
        self::assertSame("> First line.\n> Second line.\n\n", $client->getWebDriver()->findElement(WebDriverBy::cssSelector(self::REPLY_BOX))->getAttribute('value'));
    }

    private function browserWithSeededThread(string $body): Client
    {
        $this->codeViewReview();
        $this->seededRequest(3, 3, $body);
        $client = $this->browserAtFile(FixtureRepositories::SMALL_FILE);
        $client->waitFor(self::REPLY_BOX);

        return $client;
    }
}
