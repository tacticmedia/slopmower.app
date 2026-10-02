<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\RequestScreens;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

final class WontDoTest extends PantherTestCase
{
    use RequestScreens;
    use ResetDatabase;

    private const string SUBMIT = self::NOTE_DIALOG.'//button[normalize-space()="Close as won\'t do"]';

    #[TestDox("Close as won't do stays disabled below 20 characters, then closes the request as Won't do")]
    public function testCloseAsWontDoNeedsTwentyCharacters(): void
    {
        $this->codeViewReview();
        $requestId = $this->seededRequest(9, 12, 'Extract the tax calculation.')->toString();
        $client = self::browserSignedInAs($this->commenter);
        self::openRequest($client, $requestId);

        self::clickRequestButton($client, "Close as won't do");
        $textarea = self::NOTE_DIALOG.'//textarea';
        $client->waitForVisibility($textarea);
        $client->getWebDriver()->findElement(WebDriverBy::xpath($textarea))->sendKeys('  Already done in #42');
        self::assertSame('true', self::submitButton($client)->getAttribute('disabled'), '19 characters after trimming are too few.');
        $client->getWebDriver()->findElement(WebDriverBy::xpath($textarea))->sendKeys('.');
        $client->wait()->until(static fn (): bool => null === self::submitButton($client)->getAttribute('disabled'));
        self::submitButton($client)->click();

        self::waitForStatus($client, "Won't do");
        $client->waitForElementToContain(self::DETAIL.' [data-testid="thread"]', 'Already done in #42.');
    }

    private static function submitButton(Client $client): WebDriverElement
    {
        return $client->getWebDriver()->findElement(WebDriverBy::xpath(self::SUBMIT));
    }
}
