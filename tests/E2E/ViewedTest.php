<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\CodeComments;
use App\Tests\Support\Git\FixtureRepositories;
use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

final class ViewedTest extends PantherTestCase
{
    use CodeComments;
    use ResetDatabase;

    private const string CHECKBOX = '[data-testid="file-header"] [data-testid="viewed-checkbox"]';
    private const string TREE_MARK = '[data-testid="file-tree"] [data-path="'.FixtureRepositories::SMALL_FILE.'"] [data-testid="viewed-mark"]';

    #[TestDox('A file marked as viewed shows the mark in the tree after a reload')]
    public function testViewedMarkSurvivesReload(): void
    {
        $client = $this->browserAtFile(FixtureRepositories::SMALL_FILE);
        $client->waitFor('[data-testid="file-tree"] [data-path="'.FixtureRepositories::SMALL_FILE.'"]');
        self::assertSelectorNotExists(self::TREE_MARK);

        $client->getWebDriver()->findElement(WebDriverBy::cssSelector(self::CHECKBOX))->click();
        $client->waitFor(self::TREE_MARK);

        $client->reload();
        $client->waitFor(self::TREE_MARK);
        $client->waitFor(self::CHECKBOX.':checked');
    }
}
