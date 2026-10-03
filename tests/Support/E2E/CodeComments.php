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
use Playwright\Locator\LocatorInterface;

/**
 * A review of {@see FixtureRepositories::codeView()}, the comment gestures, and the seeded requests.
 */
trait CodeComments
{
    use BrowserFixtures;

    /** The host of the open shadow root that `@pierre/diffs` renders into. CSS locators pierce it. */
    private const string CODE_HOST = 'diffs-container';
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

    private function openFile(string $path): void
    {
        $this->visit("/reviews/{$this->reviewId}/files/{$path}");
        $this->expect($this->page->locator('[data-testid="file-header"]'))->toContainText($path);
    }

    /**
     * The worker replaces each row when its highlighting arrives, which ends a hover on the old row.
     */
    private function waitForHighlighting(int $line): void
    {
        $this->expect($this->page->locator(self::CODE_HOST." [data-line=\"{$line}\"] span[style]")->first())->toBeAttached();
    }

    private function lineNumber(int $line): LocatorInterface
    {
        return $this->page->locator(self::CODE_HOST." [data-column-number=\"{$line}\"]");
    }

    /**
     * Hovers the line number, then presses the "+" that the hover shows and releases it over `$releaseLine`.
     */
    private function dragFromPlus(int $pressLine, int $releaseLine): void
    {
        $this->lineNumber($pressLine)->hover();
        $plus = $this->page->locator(self::CODE_HOST.' [data-utility-button]');
        $this->expect($plus)->toHaveCount(1);
        $plus->hover();
        // The view ignores the pointer for 120 ms after each scroll, and the hover can scroll.
        $this->page->waitForFunction(<<<'JS'
            (host) => {
                const root = document.querySelector(host)?.shadowRoot;
                const plus = root?.querySelector('[data-utility-button]');
                if (!plus) {
                    return false;
                }
                const { x, y, width, height } = plus.getBoundingClientRect();
                const hit = root.elementFromPoint(x + width / 2, y + height / 2);

                return null !== hit && plus.contains(hit);
            }
            JS, self::CODE_HOST);
        $this->page->mouse()->down();
        $this->lineNumber($releaseLine)->hover();
        $this->page->mouse()->up();
    }

    /**
     * The comment box that opened below `$line` and has the focus.
     */
    private function focusedCommentBoxBelow(int $line): LocatorInterface
    {
        $textarea = $this->page->locator("[slot=\"annotation-{$line}\"] ".self::COMPOSER);
        $this->expect($textarea)->toBeVisible();
        $this->expect($textarea)->toBeFocused();

        return $textarea;
    }

    /**
     * Types into the comment box and clicks "Add single comment", then waits for the saved thread.
     */
    private function addSingleComment(LocatorInterface $textarea, string $body, string $container): void
    {
        $textarea->fill($body);
        $this->page->locator('form[data-testid="comment-box"]')->getByRole('button', ['name' => 'Add single comment', 'exact' => true])->click();
        $this->expect($this->page->locator("{$container} ".self::SAVED_THREAD)->filter(['hasText' => $body]))->toHaveCount(1);
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
