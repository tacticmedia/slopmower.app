<?php

declare(strict_types=1);

namespace App\Tests\Support\E2E;

use App\Application\AnchorFactory;
use App\Application\RequestCommands;
use App\Domain\Actor\Actor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Request\Command\Open;
use App\Entity\User;
use App\Repository\ReviewRepository;
use App\Tenancy\TenantContext;
use App\Tests\Support\Git\FixtureRepositories;
use Facebook\WebDriver\Exception\StaleElementReferenceException;
use Facebook\WebDriver\Interactions\WebDriverActions;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverHasInputDevices;
use Symfony\Component\Panther\Client;

/**
 * A review of {@see FixtureRepositories::codeView()}, the comment gestures, and the seeded requests.
 */
trait CodeComments
{
    use BrowserFixtures;

    /** The new-comment box. A reply box is labelled "Reply". */
    private const string COMPOSER = 'textarea[aria-label^="Comment on"]';
    private const string SAVED_THREAD = '[data-testid="thread"][data-request-id]';

    private User $commenter;
    private ReviewId $reviewId;

    private function codeViewReview(): void
    {
        $this->commenter = $this->user();
        $this->reviewId = self::startedReview($this->commenter, self::connectedRepository($this->commenter, $this->localSource(FixtureRepositories::codeView())), 'Comments');
    }

    private function openFile(Client $client, string $path, string $hash = ''): void
    {
        $client->request('GET', "/reviews/{$this->reviewId}/files/{$path}{$hash}");
        $client->waitForElementToContain('[data-testid="file-header"]', $path);
    }

    /**
     * The worker replaces each row when its highlighting arrives, which makes element handles stale.
     */
    private static function waitForHighlighting(Client $client, int $line): void
    {
        ShadowDom::waitUntil($client, "[data-line=\"{$line}\"] span[style]", static fn (): bool => true);
    }

    /**
     * Runs the pointer gesture with fresh shadow-root elements until no element went stale.
     *
     * @param callable(WebDriverActions, callable(string): WebDriverElement): void $gesture
     */
    private static function pointerGesture(Client $client, callable $gesture): void
    {
        $client->wait()->until(static function () use ($client, $gesture): bool {
            $host = $client->getWebDriver()->findElement(WebDriverBy::cssSelector(ShadowDom::CODE_HOST));
            \assert($host instanceof RemoteWebElement);
            $find = static fn (string $selector): WebDriverElement => $host->getShadowRoot()->findElement(WebDriverBy::cssSelector($selector));

            try {
                $gesture(self::actions($client), $find);

                return true;
            } catch (StaleElementReferenceException) {
                return false;
            }
        }, 'The pointer gesture kept hitting replaced rows.');
    }

    private static function actions(Client $client): WebDriverActions
    {
        $driver = $client->getWebDriver();
        \assert($driver instanceof WebDriverHasInputDevices);

        return new WebDriverActions($driver);
    }

    private static function lineNumber(int $line): string
    {
        return "[data-column-number=\"{$line}\"]";
    }

    /**
     * Hovers the line number, then presses the "+" that the hover shows and releases it over `$releaseLine`.
     */
    private static function dragFromPlus(Client $client, int $pressLine, int $releaseLine): void
    {
        self::pointerGesture($client, static function (WebDriverActions $actions, callable $find) use ($client, $pressLine, $releaseLine): void {
            $actions->moveToElement($find(self::lineNumber($pressLine)))->perform();
            $client->wait()->until(static fn (): bool => 1 === ShadowDom::count($client, '[data-utility-button]'));
            $actions = self::actions($client);
            $actions->clickAndHold($find('[data-utility-button]'))->moveToElement($find(self::lineNumber($releaseLine)))->release()->perform();
        });
    }

    /**
     * The comment box that opened below `$line` and has the focus.
     */
    private static function focusedCommentBoxBelow(Client $client, int $line): WebDriverElement
    {
        $textarea = "[slot=\"annotation-{$line}\"] ".self::COMPOSER;
        $client->waitForVisibility($textarea);
        $client->wait()->until(static fn (): bool => true === $client->executeScript('return document.activeElement === document.querySelector(arguments[0]);', [$textarea]));

        return $client->getWebDriver()->findElement(WebDriverBy::cssSelector($textarea));
    }

    /**
     * Types into the focused comment box and clicks "Add single comment", then waits for the saved thread.
     */
    private static function addSingleComment(Client $client, WebDriverElement $textarea, string $body, string $container): void
    {
        $textarea->sendKeys($body);
        $client->getWebDriver()->findElement(WebDriverBy::xpath('//form[@data-testid="comment-box"]//button[normalize-space(.)="Add single comment"]'))->click();
        self::waitForSavedThread($client, $container, $body);
    }

    private static function waitForSavedThread(Client $client, string $container, string $text): void
    {
        $client->wait()->until(static function () use ($client, $container, $text): bool {
            foreach ($client->getWebDriver()->findElements(WebDriverBy::cssSelector("{$container} ".self::SAVED_THREAD)) as $thread) {
                if (str_contains($thread->getText(), $text)) {
                    return true;
                }
            }

            return false;
        }, "No saved thread in {$container} shows \"{$text}\".");
    }

    private function seededRequest(int $startLine, int $endLine, string $body): RequestId
    {
        self::getContainer()->get(TenantContext::class)->set($this->commenter->getTenantId());
        $review = self::getContainer()->get(ReviewRepository::class)->findFresh($this->reviewId);
        self::assertNotNull($review);
        $kind = $startLine === $endLine ? AnchorKind::Line : AnchorKind::Range;
        $anchor = self::getContainer()->get(AnchorFactory::class)->capture($review, $kind, FixtureRepositories::SMALL_FILE, $startLine, $endLine, null);
        $requestId = self::getContainer()->get(RequestCommands::class)->open($this->reviewId, new Open($anchor, $body), Actor::user($this->commenter->getId()))[0]->requestId;
        self::assertNotNull($requestId);

        return $requestId;
    }
}
