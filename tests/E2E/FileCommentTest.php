<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\CodeComments;
use App\Tests\Support\E2E\ShadowDom;
use App\Tests\Support\Git\FixtureRepositories;
use Facebook\WebDriver\Interactions\WebDriverActions;
use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

final class FileCommentTest extends PantherTestCase
{
    use CodeComments;
    use ResetDatabase;

    private const string FILE_THREADS = '[data-testid="file-threads"]';

    #[TestDox('The file comment button creates a file anchor, also on a binary file')]
    public function testFileCommentButtonCreatesFileAnchors(): void
    {
        $client = $this->browserAtFile(FixtureRepositories::SMALL_FILE);
        self::commentOnFile($client, 'Split this class.');

        $this->openFile($client, FixtureRepositories::BINARY_FILE);
        $client->waitFor('[data-testid="file-placeholder"]');
        self::commentOnFile($client, 'Use an SVG.');

        self::assertSame(
            [
                ['kind' => 'file', 'path' => FixtureRepositories::SMALL_FILE, 'range' => null, 'body' => 'Split this class.'],
                ['kind' => 'file', 'path' => FixtureRepositories::BINARY_FILE, 'range' => null, 'body' => 'Use an SVG.'],
            ],
            $this->savedRequests(),
        );
        $client->reload();
        self::waitForSavedThread($client, self::FILE_THREADS, 'Use an SVG.');
    }

    #[TestDox('A closed review shows no comment commands and no "+"')]
    public function testClosedReviewShowsNoCommentControls(): void
    {
        $this->codeViewReview();
        $this->seededRequest(3, 3, 'Seeded before the close.');
        $this->closeReview();

        $client = $this->browserAtFile(FixtureRepositories::SMALL_FILE);
        self::waitForSavedThread($client, '[slot="annotation-3"]', 'Seeded before the close.');
        self::waitForHighlighting($client, 5);
        self::pointerGesture($client, static function (WebDriverActions $actions, callable $find): void {
            $actions->moveToElement($find(self::lineNumber(5)))->perform();
        });

        self::assertSelectorNotExists('//button[normalize-space(.)="Comment on file" or normalize-space(.)="Comment on lines"]');
        self::assertSelectorNotExists('textarea[aria-label="Reply"]');
        self::assertSame(0, ShadowDom::count($client, '[data-utility-button]'));
    }

    #[TestDox('A failed comment opens its box again with the text and the error')]
    public function testFailedCommentKeepsItsText(): void
    {
        $client = $this->browserAtFile(FixtureRepositories::SMALL_FILE);
        $client->getWebDriver()->findElement(WebDriverBy::xpath('//button[normalize-space(.)="Comment on file"]'))->click();
        $client->waitForVisibility(self::FILE_THREADS.' '.self::COMPOSER);
        $this->closeReview();

        $client->getWebDriver()->findElement(WebDriverBy::cssSelector(self::FILE_THREADS.' '.self::COMPOSER))->sendKeys('Too late.');
        $client->getWebDriver()->findElement(WebDriverBy::xpath('//button[normalize-space(.)="Add single comment"]'))->click();

        $client->waitFor(self::FILE_THREADS.' [data-testid="comment-error"]');
        self::assertSame('Too late.', $client->getWebDriver()->findElement(WebDriverBy::cssSelector(self::FILE_THREADS.' '.self::COMPOSER))->getAttribute('value'));
        self::assertSelectorNotExists('[data-testid="thread"]');
        self::assertSame([], $this->savedRequests());
    }

    private static function commentOnFile(Client $client, string $body): void
    {
        $client->getWebDriver()->findElement(WebDriverBy::xpath('//button[normalize-space(.)="Comment on file"]'))->click();
        $textarea = self::FILE_THREADS.' '.self::COMPOSER;
        $client->waitForVisibility($textarea);
        self::addSingleComment($client, $client->getWebDriver()->findElement(WebDriverBy::cssSelector($textarea)), $body, self::FILE_THREADS);
    }
}
