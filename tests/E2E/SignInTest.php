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
    public function testSessionSurvivesAReload(): void
    {
        $client = self::createPantherClient();

        $client->request('GET', '/_e2e/sign-in/alice');
        $client->waitForElementToContain('#signed-in-login', 'alice');
        $client->executeScript('document.body.dataset.beforeReload = "yes"');

        $client->reload();
        $client->waitForElementToContain('#signed-in-login', 'alice');

        self::assertNull($client->executeScript('return document.body.dataset.beforeReload ?? null'), 'The page did not reload.');
        $sessionId = $client->getCookieJar()->get(session_name() ?: 'PHPSESSID')?->getValue();
        self::assertNotNull($sessionId);
        self::assertSame(1, self::getContainer()->get(Connection::class)->fetchOne('SELECT count(*) FROM sessions WHERE sess_id = ?', [$sessionId]));
    }
}
