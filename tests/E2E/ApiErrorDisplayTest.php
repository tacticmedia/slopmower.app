<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Domain\Error\CommitNotFound;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\BrowserFixtures;
use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class ApiErrorDisplayTest extends PantherTestCase
{
    use BrowserFixtures;
    use ResetDatabase;

    private const string FORM = '[data-testid="start-review-form"]';

    #[TestDox('Starting a review at an unknown ref shows the API message')]
    public function testUnknownRefShowsTheApiMessage(): void
    {
        $user = $this->user();
        $repository = self::connectedRepository($user, $this->localSource());
        $client = self::browserSignedInAs($user);
        $client->request('GET', '/repositories/'.$repository->getId());
        $client->waitFor(self::FORM.' input[name="ref"]');
        $ref = $client->getWebDriver()->findElement(WebDriverBy::cssSelector(self::FORM.' input[name="ref"]'));
        $ref->clear();
        $ref->sendKeys('no-such-branch');

        $client->getWebDriver()->findElement(WebDriverBy::cssSelector(self::FORM.' button[type="submit"]'))->click();

        $client->waitFor(self::FORM.' [data-testid="action-error"]');
        self::assertSelectorTextSame(self::FORM.' [data-testid="action-error"]', new CommitNotFound('no-such-branch')->getMessage());
    }
}
