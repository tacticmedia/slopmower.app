<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class E2eRouteAbsentTest extends WebTestCase
{
    #[TestDox('The e2e sign-in route gives 404 in the test environment')]
    public function testE2eSignInRouteIsAbsent(): void
    {
        $client = static::createClient();

        $client->request('GET', '/_e2e/sign-in/jan');

        self::assertResponseStatusCodeSame(404);
    }
}
