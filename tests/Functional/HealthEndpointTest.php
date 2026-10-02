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
        $client = static::createClient();

        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('title');
        self::assertSelectorExists('span#js');
    }
}
