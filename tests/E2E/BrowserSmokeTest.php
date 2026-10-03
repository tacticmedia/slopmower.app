<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\E2E\FullStackTestCase;
use PHPUnit\Framework\Attributes\TestDox;

final class BrowserSmokeTest extends FullStackTestCase
{
    #[TestDox('The browser on port 8081 sees the text ok in the js marker')]
    public function testBrowserRunsInlineScript(): void
    {
        $this->visit('/healthz');

        $this->expect($this->page->locator('#js'))->toHaveExactText('ok');
        self::assertSame(self::BASE_URI.'/healthz', $this->page->url());
        $this->expect($this->page->locator('body'))->toHaveAttribute('data-environment', 'e2e');
    }
}
