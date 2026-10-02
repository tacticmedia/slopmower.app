<?php

declare(strict_types=1);

namespace App\Tests\Functional\Support;

use App\Tests\Support\Database\ResetDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ResetDatabaseTest extends WebTestCase
{
    use ResetDatabase;

    #[TestDox('A WebTestCase that resets the database can still create a client')]
    public function testWebTestCaseCanCreateAClient(): void
    {
        $client = static::createClient();

        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
    }
}
