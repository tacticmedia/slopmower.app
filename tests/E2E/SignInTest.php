<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\FullStackTestCase;
use App\Tests\Support\E2E\SessionFiles;
use PHPUnit\Framework\Attributes\TestDox;

final class SignInTest extends FullStackTestCase
{
    use ResetDatabase;
    use SessionFiles;

    #[TestDox('After the e2e sign-in and a reload, the user is still signed in and one session file holds the token')]
    public function testSessionSurvivesReload(): void
    {
        $this->signInAs('jan');
        $this->visit('/api/v1/me');

        $this->page->reload();

        $me = json_decode((string) $this->page->locator('pre')->first()->textContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($me);
        self::assertSame('jan', $me['user']['login']);
        self::assertSame([true], self::sessionsHoldSecurityToken());
    }
}
