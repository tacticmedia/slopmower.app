<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthEndpointTest extends WebTestCase
{
    #[TestDox('GET /healthz returns 200 with an HTML page')]
    public function testHealthzReturnsHtmlPage(): void
    {
        $client = self::createClient();

        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        self::assertPageTitleSame('Health');
        self::assertSelectorExists('html[data-environment="test"] span#js');
    }
}
