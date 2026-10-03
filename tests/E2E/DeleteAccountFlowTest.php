<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\BrowserFixtures;
use App\Tests\Support\E2E\FullStackTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The e2e site runs the purge job during A2, through `sync://`.
 */
final class DeleteAccountFlowTest extends FullStackTestCase
{
    use BrowserFixtures;
    use ResetDatabase;

    #[TestDox('Typing the login and confirming signs the user out')]
    public function testTypingTheLoginSignsTheUserOut(): void
    {
        $user = $this->user();
        self::connectedRepository($user, $this->localSource());
        $this->signInAs($user->getLogin());
        $this->visit('/settings');

        $this->page->locator('main')->getByRole('button', ['name' => 'Delete account', 'exact' => true])->click();
        $dialog = $this->page->getByRole('dialog');
        $dialog->locator('input')->fill('jan');
        $dialog->locator('button[type="submit"]')->click();

        $this->page->waitForURL(self::BASE_URI.'/login');
        $this->expect($this->page->locator('h1'))->toContainText('Sign in to Holistic Review');
        $this->visit('/settings');
        $this->page->waitForURL(self::BASE_URI.'/login');
        self::assertSame(
            ['app_user' => 0, 'signed_in_sessions' => 0],
            self::getContainer()->get(Connection::class)->fetchAssociative(
                "SELECT (SELECT count(*) FROM app_user) AS app_user, (SELECT count(*) FROM sessions WHERE position('_security_web'::bytea IN sess_data) > 0) AS signed_in_sessions",
            ),
        );
    }
}
