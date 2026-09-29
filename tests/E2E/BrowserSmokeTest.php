<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class BrowserSmokeTest extends PantherTestCase
{
    #[TestDox('The browser on port 8081 sees the text ok in the js marker')]
    public function testBrowserRunsJavaScriptOnTheE2eSite(): void
    {
        $client = self::createPantherClient();

        $client->request('GET', '/healthz');
        $client->waitForElementToContain('html[data-environment="e2e"] #js', 'ok');

        self::assertSelectorTextSame('#js', 'ok');
    }
}
