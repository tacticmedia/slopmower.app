<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\BrowserFixtures;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class DeleteReviewFlowTest extends PantherTestCase
{
    use BrowserFixtures;
    use ResetDatabase;

    private const string OPEN_DELETE_DIALOG = '//header//button[normalize-space()="Delete review"]';

    private const string REVIEW_LIST = '[data-testid="review-list"]';

    #[TestDox('Confirming the dialog deletes the review and shows the repository page without it')]
    public function testConfirmingDeletesTheReview(): void
    {
        $user = $this->user();
        $repository = self::connectedRepository($user, $this->localSource());
        self::startedReview($user, $repository, 'Kept review');
        $deleted = self::startedReview($user, $repository, 'Deleted review');
        $client = self::browserSignedInAs($user);
        $client->request('GET', "/reviews/{$deleted}");
        $client->waitFor(self::OPEN_DELETE_DIALOG);

        $client->getWebDriver()->findElement(WebDriverBy::xpath(self::OPEN_DELETE_DIALOG))->click();
        $client->waitForVisibility('[role="dialog"] button[type="submit"]');
        $client->getWebDriver()->findElement(WebDriverBy::cssSelector('[role="dialog"] button[type="submit"]'))->click();

        $client->wait()->until(WebDriverExpectedCondition::urlMatches('#/repositories/'.$repository->getId().'$#'));
        $client->waitFor(self::REVIEW_LIST.' table');
        self::assertSelectorTextContains(self::REVIEW_LIST.' table', 'Kept review');
        self::assertSelectorTextNotContains(self::REVIEW_LIST.' table', 'Deleted review');
    }
}
