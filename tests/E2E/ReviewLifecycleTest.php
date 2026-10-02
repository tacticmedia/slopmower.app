<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\BrowserFixtures;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Component\Uid\Ulid;

final class ReviewLifecycleTest extends PantherTestCase
{
    use BrowserFixtures;
    use ResetDatabase;

    private const string REVIEW_LIST = '[data-testid="review-list"]';

    private const string OPEN_CLOSE_DIALOG = '//header//button[normalize-space()="Close review"]';

    #[TestDox('The review page shows the review ID and the prompt snippet')]
    public function testReviewPageShowsTheIdAndThePrompt(): void
    {
        $user = $this->user();
        $repository = self::connectedRepository($user, $this->localSource());
        $client = self::browserSignedInAs($user);
        $client->request('GET', '/repositories/'.$repository->getId());
        $client->waitFor('[data-testid="start-review-form"] input[name="ref"][value="main"]');

        self::click($client, '[data-testid="start-review-form"] button[type="submit"]');

        $client->wait()->until(WebDriverExpectedCondition::urlMatches('#/reviews/[0-9A-HJKMNP-TV-Z]{26}$#'));
        $reviewId = basename((string) parse_url($client->getCurrentURL(), \PHP_URL_PATH));
        $client->waitForElementToContain('[data-testid="review-id"]', $reviewId);
        self::assertSelectorTextSame('[data-testid="agent-prompt"]', "Work on Holistic Review {$reviewId}");
    }

    #[TestDox('The copy button shows Copied')]
    public function testCopyButtonShowsCopied(): void
    {
        $user = $this->user();
        $reviewId = self::startedReview($user, self::connectedRepository($user, $this->localSource()), 'Copy review');
        $client = self::browserSignedInAs($user);
        $client->request('GET', "/reviews/{$reviewId}");
        $copyButton = '//*[@data-testid="review-id"]/following-sibling::*[1]//button';
        $client->waitFor($copyButton);

        $client->getWebDriver()->findElement(WebDriverBy::xpath($copyButton))->click();

        $client->waitForElementToContain($copyButton, 'Copied');
    }

    #[TestDox('Two open reviews of one repository are listed')]
    public function testTwoOpenReviewsAreListed(): void
    {
        $user = $this->user();
        $repository = self::connectedRepository($user, $this->localSource());
        self::startedReview($user, $repository, 'First review');
        self::startedReview($user, $repository, 'Second review');
        $client = self::browserSignedInAs($user);

        $client->request('GET', '/repositories/'.$repository->getId());

        $client->waitFor(self::REVIEW_LIST.' table');
        self::assertEqualsCanonicalizing(['First review', 'Second review'], self::reviewTitles($client));
    }

    #[TestDox('After close, the review is hidden, shows with Show closed, and is read-only')]
    public function testClosedReviewIsHiddenAndReadOnly(): void
    {
        $user = $this->user();
        $repository = self::connectedRepository($user, $this->localSource());
        $reviewId = self::startedReview($user, $repository, 'Closing review');
        $client = self::browserSignedInAs($user);
        $client->request('GET', "/reviews/{$reviewId}");
        $client->waitFor(self::OPEN_CLOSE_DIALOG);

        $client->getWebDriver()->findElement(WebDriverBy::xpath(self::OPEN_CLOSE_DIALOG))->click();
        $client->waitForVisibility('[role="dialog"] textarea[name="note"]');
        $client->getWebDriver()->findElement(WebDriverBy::cssSelector('[role="dialog"] textarea[name="note"]'))->sendKeys('Shipped in 2.4');
        self::click($client, '[role="dialog"] button[type="submit"]');

        $client->waitForElementToContain('[data-testid="review-closed"]', 'Shipped in 2.4');
        self::assertCount(0, $client->getCrawler()->filterXPath(self::OPEN_CLOSE_DIALOG));

        self::click($client, 'header a[href="/repositories/'.$repository->getId().'"]');
        $client->waitForElementToContain(self::REVIEW_LIST, 'This repository has no open reviews.');
        self::click($client, self::REVIEW_LIST.' input[type="checkbox"]');

        $client->waitForElementToContain(self::REVIEW_LIST.' table', 'Closing review');
        self::assertSame(['Closing review'], self::reviewTitles($client));
        self::assertSelectorTextContains(self::REVIEW_LIST.' table', 'Closed');
    }

    #[TestDox('An unknown review ID shows the route error, and the header stays')]
    public function testUnknownReviewKeepsTheHeader(): void
    {
        $client = self::browserSignedInAs($this->user());

        $client->request('GET', '/reviews/'.new Ulid());

        $client->waitFor('main [data-testid="route-error"]');
        self::assertSelectorTextSame('header [data-testid="current-user"]', 'jan');
    }

    private static function click(Client $client, string $selector): void
    {
        $client->getWebDriver()->findElement(WebDriverBy::cssSelector($selector))->click();
    }

    /**
     * @return list<string>
     */
    private static function reviewTitles(Client $client): array
    {
        return $client->getCrawler()->filter(self::REVIEW_LIST.' tbody th')->each(static fn (Crawler $title): string => $title->text());
    }
}
