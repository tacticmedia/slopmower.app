<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\E2E\FullStackTestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Playwright\Locator\LocatorInterface;

final class CookieNoticeTest extends FullStackTestCase
{
    #[TestDox('On the first visit, the notice shows and links the privacy policy')]
    public function testNoticeShowsOnFirstVisit(): void
    {
        $this->visit('/login');

        $region = $this->page->getByRole('region', ['name' => 'Cookie notice', 'exact' => true]);
        $this->expect($region)->toBeVisible();
        $this->expect($region->getByRole('link', ['name' => 'Privacy policy', 'exact' => true]))->toHaveAttribute('href', '/docs/privacy');
    }

    #[TestDox('"Got it" closes the notice, and it stays closed after a reload')]
    public function testGotItClosesTheNoticeForGood(): void
    {
        $this->visit('/login');
        $this->expect($this->notice())->toBeVisible();

        $this->notice()->getByRole('button', ['name' => 'Got it', 'exact' => true])->click();
        $this->expect($this->notice())->toBeHidden();

        $this->page->reload();
        $this->expect($this->page->locator('h1'))->toContainText('Sign in to Slopmower');
        $this->expect($this->notice())->toBeHidden();
    }

    /**
     * CSS, not a role: a role locator matches no hidden element, and an expect() on a missing element waits 30 s.
     */
    private function notice(): LocatorInterface
    {
        return $this->page->locator('section[aria-label="Cookie notice"]');
    }
}
