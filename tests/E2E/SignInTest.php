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

    #[TestDox('After the double-submit sign-out, a Twig form with the stateless CSRF placeholder passes the check')]
    public function testTwigFormAfterSignOutPassesTheCsrfCheck(): void
    {
        $this->signInAs('jan');
        $this->visit('/');
        $this->page->getByRole('button', ['name' => 'Sign out'])->click();
        $this->page->waitForURL(self::BASE_URI.'/login');

        // The e2e site has no CSRF-checked Twig form, because local sign-in exists only in dev.
        $this->visit('/docs');
        // requestSubmit() dispatches the submit event, as a click on the submit button does.
        $this->page->evaluate(<<<'JS'
            document.body.insertAdjacentHTML('beforeend', '<form id="probe" method="post" action="/logout"><input type="hidden" name="_csrf_token" value="csrf-token"></form>');
            document.getElementById('probe').requestSubmit();
            JS);

        $this->page->waitForURL(self::BASE_URI.'/login');
        $this->expect($this->page->locator('h1'))->toContainText('Sign in to Holistic Review');
    }
}
