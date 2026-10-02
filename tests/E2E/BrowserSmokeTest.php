<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class BrowserSmokeTest extends PantherTestCase
{
    #[TestDox('The browser on port 8081 sees the text ok in the js marker')]
    public function testBrowserRunsInlineScript(): void
    {
        $client = static::createPantherClient();

        $client->request('GET', '/healthz');

        $client->waitForElementToContain('#js', 'ok');
        self::assertSame('http://localhost:8081/healthz', $client->getCurrentURL());
        self::assertSame('e2e', $client->getCrawler()->filter('body')->attr('data-environment'));
        self::assertSelectorTextSame('#js', 'ok');
    }
}
