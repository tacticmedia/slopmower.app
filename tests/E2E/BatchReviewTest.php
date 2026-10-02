<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\Draft;
use App\Repository\DraftRepository;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\CodeComments;
use App\Tests\Support\Git\FixtureRepositories;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverKeys;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

final class BatchReviewTest extends PantherTestCase
{
    use CodeComments;
    use ResetDatabase;

    private const string FILE_THREADS = '[data-testid="file-threads"]';
    private const string SAVED_DRAFT = '[data-testid="draft"][data-draft-id]';
    private const string FINISH = '[data-testid="finish-review"]';

    #[TestDox('Two drafts submitted with a summary appear as two threads without the Pending label')]
    public function testSubmittedDraftsBecomeThreads(): void
    {
        $client = $this->browserAtFile(FixtureRepositories::SMALL_FILE);
        self::commentOnFile($client, 'Split this class.', 'Start a review');
        self::waitForHighlighting($client, 10);
        self::dragFromPlus($client, 10, 10);
        self::addReviewComment($client, self::focusedCommentBoxBelow($client, 10), 'Use a named constant.', 'Add review comment', '[slot="annotation-10"]');
        $client->waitForElementToContain(self::FINISH, '2');
        self::assertSame([], $this->savedRequests(), 'Drafts are not requests.');

        $client->getWebDriver()->findElement(WebDriverBy::cssSelector(self::FINISH))->click();
        $summary = '[data-testid="finish-review-form"] textarea[name="summary"]';
        $client->waitForVisibility($summary);
        $client->getWebDriver()->findElement(WebDriverBy::cssSelector($summary))->sendKeys('First pass.');
        $client->getWebDriver()->findElement(WebDriverBy::xpath('//form[@data-testid="finish-review-form"]//button[normalize-space(.)="Submit review"]'))->click();

        self::waitForSavedThread($client, self::FILE_THREADS, 'Split this class.');
        self::waitForSavedThread($client, '[slot="annotation-10"]', 'Use a named constant.');
        self::waitForAbsence($client, self::FINISH);
        self::assertSelectorNotExists('[data-testid="pending-label"]');
        self::assertSame(
            [
                ['kind' => 'file', 'path' => FixtureRepositories::SMALL_FILE, 'range' => null, 'body' => 'Split this class.'],
                ['kind' => 'line', 'path' => FixtureRepositories::SMALL_FILE, 'range' => [10, 10], 'body' => 'Use a named constant.'],
            ],
            $this->savedRequests(),
        );
        self::assertSame('First pass.', self::getContainer()->get(Connection::class)->fetchOne("SELECT payload->>'summary' FROM review_event WHERE type = 'batch.submitted'"));
    }

    #[TestDox('A draft can be edited and deleted before the submission')]
    public function testDraftCanBeEditedAndDeleted(): void
    {
        $client = $this->browserAtFile(FixtureRepositories::SMALL_FILE);
        self::commentOnFile($client, 'Split this class.', 'Start a review');
        self::commentOnFile($client, 'Delete me.', 'Add review comment');
        $client->waitForElementToContain(self::FINISH, '2');

        self::chooseDraftAction($client, 'Split this class.', 'Edit');
        $editor = self::FILE_THREADS.' textarea[aria-label="Edit pending comment"]';
        $client->waitForVisibility($editor);
        $editorField = $client->getWebDriver()->findElement(WebDriverBy::cssSelector($editor));
        $editorField->sendKeys([WebDriverKeys::CONTROL, 'a']);
        $editorField->sendKeys('Split this class in two.');
        $client->getWebDriver()->findElement(WebDriverBy::xpath('//form[@data-testid="comment-box"]//button[normalize-space(.)="Update comment"]'))->click();
        self::waitForAbsence($client, $editor);
        self::waitForDraft($client, 'Split this class in two.');

        self::chooseDraftAction($client, 'Delete me.', 'Delete');
        $client->waitForElementToContain(self::FINISH, '1');

        self::assertSame(['Split this class in two.'], array_map(static fn (Draft $draft): string => $draft->getBody(), $this->storedDrafts()));
        self::assertCount(1, $client->getWebDriver()->findElements(WebDriverBy::cssSelector(self::SAVED_DRAFT)));
    }

    #[TestDox('The close dialog warns with the draft count')]
    public function testCloseDialogWarnsAboutDrafts(): void
    {
        $client = $this->browserAtFile(FixtureRepositories::SMALL_FILE);
        self::commentOnFile($client, 'Split this class.', 'Start a review');
        self::commentOnFile($client, 'Name the constants.', 'Add review comment');

        $client->getWebDriver()->findElement(WebDriverBy::xpath('//button[normalize-space(.)="Close review"]'))->click();

        $client->waitForElementToContain('[data-testid="close-drafts-warning"]', 'You have 2 pending comments');
    }

    /**
     * Opens a file comment, types the text, and clicks the review button.
     */
    private static function commentOnFile(Client $client, string $body, string $reviewButton): void
    {
        $client->getWebDriver()->findElement(WebDriverBy::xpath('//button[normalize-space(.)="Comment on file"]'))->click();
        $textarea = self::FILE_THREADS.' '.self::COMPOSER;
        $client->waitForVisibility($textarea);
        self::addReviewComment($client, $client->getWebDriver()->findElement(WebDriverBy::cssSelector($textarea)), $body, $reviewButton, self::FILE_THREADS);
    }

    /**
     * Types into the comment box, clicks "Start a review" or "Add review comment", then waits for the saved draft.
     */
    private static function addReviewComment(Client $client, WebDriverElement $textarea, string $body, string $button, string $container): void
    {
        $textarea->sendKeys($body);
        $client->getWebDriver()->findElement(WebDriverBy::xpath("//form[@data-testid=\"comment-box\"]//button[normalize-space(.)=\"{$button}\"]"))->click();
        self::waitForDraft($client, $body, $container);
    }

    private static function waitForDraft(Client $client, string $body, string $container = self::FILE_THREADS): void
    {
        $client->wait()->until(static function () use ($client, $body, $container): bool {
            foreach ($client->getWebDriver()->findElements(WebDriverBy::cssSelector("{$container} ".self::SAVED_DRAFT)) as $draft) {
                if (str_contains($draft->getText(), $body) && str_contains($draft->getText(), 'Pending')) {
                    return true;
                }
            }

            return false;
        }, "No pending comment in {$container} shows \"{$body}\".");
    }

    private static function waitForAbsence(Client $client, string $selector): void
    {
        $client->wait()->until(static fn (): bool => [] === $client->getWebDriver()->findElements(WebDriverBy::cssSelector($selector)), "{$selector} is still there.");
    }

    private static function chooseDraftAction(Client $client, string $body, string $action): void
    {
        $client->getWebDriver()->findElement(WebDriverBy::xpath("//article[@data-testid=\"draft\"][contains(., \"{$body}\")]//button[@aria-haspopup=\"true\"]"))->click();
        $item = "//li[normalize-space(.)=\"{$action}\"]";
        $client->waitForVisibility($item);
        $client->getWebDriver()->findElement(WebDriverBy::xpath($item))->click();
    }

    /**
     * @return list<Draft>
     */
    private function storedDrafts(): array
    {
        self::getContainer()->get(TenantContext::class)->set($this->commenter->getTenantId());
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(DraftRepository::class)->findOfUser($this->commenter->getId(), $this->reviewId);
    }
}
