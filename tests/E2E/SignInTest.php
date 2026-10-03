<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\FullStackTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;

final class SignInTest extends FullStackTestCase
{
    use ResetDatabase;

    #[TestDox('After the e2e sign-in and a reload, the user is still signed in and a row exists in sessions')]
    public function testSessionSurvivesReload(): void
    {
        $this->signInAs('jan');
        $this->visit('/api/v1/me');

        $this->page->reload();

        $me = json_decode((string) $this->page->locator('pre')->first()->textContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($me);
        self::assertSame('jan', $me['user']['login']);
        $holdsSecurityToken = self::getContainer()->get(Connection::class)->fetchFirstColumn(
            "SELECT position('_security_web'::bytea IN sess_data) > 0 FROM sessions",
        );
        self::assertSame([true], $holdsSecurityToken);
    }
}
