<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class SignInTest extends PantherTestCase
{
    use ResetDatabase;

    #[TestDox('After the e2e sign-in and a reload, the user is still signed in and a row exists in sessions')]
    public function testSessionSurvivesReload(): void
    {
        $client = static::createPantherClient();
        $client->request('GET', '/_e2e/sign-in/jan');
        $client->request('GET', '/api/v1/me');

        $crawler = $client->reload();

        $me = json_decode($crawler->filter('pre')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($me);
        self::assertSame('jan', $me['user']['login']);
        $holdsSecurityToken = self::getContainer()->get(Connection::class)->fetchFirstColumn(
            "SELECT position('_security_web'::bytea IN sess_data) > 0 FROM sessions",
        );
        self::assertSame([true], $holdsSecurityToken);
    }
}
